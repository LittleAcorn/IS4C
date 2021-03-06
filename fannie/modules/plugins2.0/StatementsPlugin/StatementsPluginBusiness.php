<?php
include_once(dirname(__FILE__) . '/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(dirname(__FILE__) . '/../../../classlib2.0/FannieAPI.php');
}
if (!class_exists('FPDF')) {
    include(dirname(__FILE__) . '/../../../src/fpdf/fpdf.php');
}

class StatementsPluginBusiness extends FannieRESTfulPage
{
    public $page_set = 'Plugin :: StatementsPlugin';
    public $description = '[Business Statement PDF] generates business invoices';
    public $themed = true;

    public function post_id_handler()
    {
        global $FANNIE_OP_DB, $FANNIE_TRANS_DB, $FANNIE_ROOT, $FANNIE_ARCHIVE_DB;
        $dbc = FannieDB::get($FANNIE_OP_DB);

        $cards = "(";
        $args = array();
        if (!is_array($this->id)) {
            $this->id = array($this->id);
        }
        foreach($this->id as $c) {
            $cards .= "?,";
            $args[] = $c;
        }
        $cards = rtrim($cards,",");
        $cards .= ")";

        $cardsClause = " AND m.card_no IN $cards ";
        if ($cards == "(") $cardsClause = "";

        /**
          Look up transactions involving AR over last 90 days
        */
        $transP = $dbc->prepare("
            SELECT card_no, 
                CASE WHEN trans_subtype='MI' THEN -total ELSE 0 END AS charges,
                CASE WHEN department=990 then total ELSE 0 END as payments, 
                tdate, 
                trans_num
            FROM " . $FANNIE_TRANS_DB . $dbc->sep() . "dlog_90_view AS m 
            WHERE m.card_no IN " . $cards . "
                AND (department=990 OR trans_subtype='MI')
            ORDER BY card_no, 
                tdate, 
                trans_num");
        $transP = $dbc->prepare("
            SELECT card_no,
                charges,
                payments,
                tdate,
                trans_num,
                'OLD' as timespan
            FROM " . $FANNIE_TRANS_DB . $dbc->sep() . "ar_history 
            WHERE card_no IN " . $cards . "
                AND tdate >= ?
            UNION ALL
            SELECT card_no,
                charges,
                payments,
                tdate,
                trans_num,
                'TODAY' as timespan
            FROM " . $FANNIE_TRANS_DB . $dbc->sep() . "ar_history_today
            WHERE card_no IN " . $cards . "
            ORDER BY tdate");
        $date = date('Y-m-d', mktime(0, 0, 0, date('n'), date('j')-90, date('Y')));
        $trans_args = $args;
        $trans_args[] = $date;
        foreach ($args as $a) { // need cards twice for the union
            $trans_args[] = $a;
        }
        $transR = $dbc->execute($transP, $trans_args);

        $arRows = array();
        while ($w = $dbc->fetch_row($transR)) {
            if (!isset($arRows[$w['card_no']])) {
                $arRows[$w['card_no']] = array();
            }
            $arRows[$w['card_no']][] = $w;
            $date = explode(' ',$w['tdate']);
            $date_id = date('Ymd', strtotime($date[0]));
        }

        /**
          Lookup details of AR related transactions
          Stucture is:
          * card_no
            => trans_num
               => line item description(s)
        */
        $detailsQ = '
            SELECT card_no,
                description,
                department,
                trans_num
            FROM ' . $FANNIE_ARCHIVE_DB . $dbc->sep() . 'dlogBig
            WHERE tdate BETWEEN ? AND ?
                AND trans_num=?
                AND card_no=?
                AND trans_type IN (\'I\', \'D\')
        ';         
        $todayQ = str_replace($FANNIE_ARCHIVE_DB . $dbc->sep() . 'dlogBig', $FANNIE_TRANS_DB . $dbc->sep() . 'dlog', $detailsQ);
        $detailsP = $dbc->prepare($detailsQ);
        $todayP = $dbc->prepare($todayQ);
        $details = array();
        foreach ($arRows as $card_no => $trans) {
            $found_charge = false;
            foreach ($trans as $info) {
                if ($info['charges'] != 0) {
                    $found_charge = true;
                }
                $dt = strtotime($info['tdate']);
                $args = array(
                    date('Y-m-d 00:00:00', $dt),
                    date('Y-m-d 23:59:59', $dt),
                    $info['trans_num'],
                    $info['card_no'],
                );
                if ($info['timespan'] == 'TODAY') {
                    $r = $dbc->execute($todayP, $args);
                } else {
                    $r = $dbc->execute($detailsP, $args);
                }
                while ($w = $dbc->fetch_row($r)) {
                    $tn = $w['trans_num'];
                    if (!isset($details[$w['card_no']])) {
                        $details[$w['card_no']] = array();
                    }
                    if (!isset($details[$w['card_no']][$tn])) {
                        $details[$w['card_no']][$tn] = array();
                    }
                    $details[$w['card_no']][$tn][] = $w['description'];
                }
            }
            if ($found_charge) {
                $actual = array();
                $i=0;
                while ($arRows[$card_no][$i]['charges'] == 0) {
                    $i++;
                }
                for ($i; $i<count($arRows[$card_no]); $i++) {
                    $actual[] = $arRows[$card_no][$i];
                }
                $arRows[$card_no] = $actual;
            }
        }

        $today= date("d-F-Y");
        $month = date("n");
        $year = date("Y");

        $stateDate = date("d F, Y",mktime(0,0,0,date('n'),0,date('Y')));

        $pdf = new FPDF('P', 'mm', 'Letter');
        $pdf->AddFont('Gill', '', 'GillSansMTPro-Medium.php');
        $pdf->SetAutoPageBreak(false);

        //Meat of the statement
        $balP = $dbc->prepare('
            SELECT balance
            FROM ' . $this->config->get('TRANS_DB') . $dbc->sep() . 'ar_live_balance
            WHERE card_no=?');
        $rowNum=0;
        foreach ($this->id as $card_no) {
            $account = \COREPOS\Fannie\API\member\MemberREST::get($card_no);
            $primary = array();
            foreach ($account['customers'] as $c) {
                if ($c['accountHolder']) {
                    $primary = $c;
                    break;
                }
            }
            $balance = $dbc->getValue($balP, array($card_no));

            $pdf->AddPage();
            $pdf->Image('new_letterhead_horizontal.png',5,10, 200);
            $pdf->SetFont('Gill','','12');
            $pdf->Ln(45);

            $pdf->Cell(10,5,sprintf("Invoice #: %s-%s",$card_no,date("ymd")),0,1,'L');
            $pdf->Cell(10,5,$stateDate,0);
            $pdf->Ln(8);

            //Member address
            $name = $primary['lastName'];
            if (!empty($primary['firstName'])) {
                $name = $primary['firstName'].' '.$name;
            }
            $pdf->Cell(50,10,trim($card_no).' '.trim($name),0);
            $pdf->Ln(5);

            $pdf->Cell(80, 10, $account['addressFirstLine'], 0);
            $pdf->Ln(5);
            if ($account['addressSecondLine']) {
                $pdf->Cell(80, 10, $account['addressSecondLine'], 0);
                $pdf->Ln(5);
            }
            $pdf->Cell(90,10,$account['city'] . ', ' . $account['state'] . '   ' . $account['zip'],0);
            $pdf->Ln(25);
 
            $txt = "If payment has been made or sent, please ignore this invoice. If you have any questions about this invoice or would like to make arrangements to pay your balance, please write or call the Finance Department at the above address or (218) 728-0884.";
            $pdf->MultiCell(0,5,$txt);
            $pdf->Ln(10);

            $priorQ = $dbc->prepare("
                SELECT SUM(charges) - SUM(payments) AS priorBalance
                FROM " . $FANNIE_TRANS_DB . $dbc->sep() . "ar_history
                WHERE ".$dbc->datediff('tdate',$dbc->now())." < -90
                    AND card_no = ?");
            $priorR = $dbc->execute($priorQ, array($card_no));
            $priorW = $dbc->fetch_row($priorR);
            $priorBalance = is_array($priorW) ? $priorW['priorBalance'] : 0;

            $indent = 10;
            $columns = array(75, 35, 30, 30);
            $pdf->Cell($indent,8,'');
            $pdf->SetFillColor(200);
            $pdf->Cell(40,8,'Balance Forward',0,0,'L',1);
            $pdf->Cell(25,8,'$ ' . sprintf("%.2f",$priorBalance),0,0,'L');
            $pdf->Ln(8);
 
            $pdf->Cell(0,8,"90-Day Billing History",0,1,'C');
            $pdf->SetFillColor(200);
            $pdf->Cell($indent,8,'',0,0,'L');
            $pdf->Cell($columns[0],8,'Date',0,0,'L',1);
            $pdf->Cell($columns[1],8,'Receipt',0,0,'L',1);
            $pdf->Cell($columns[2],8,'',0,0,'L',1);
            $pdf->Cell($columns[3],8,'Amount',0,1,'L',1);
 
            $gazette = false;
            if (!isset($arRows[$card_no])) {
                $arRows[$card_no] = array();
            }
            foreach ($arRows[$card_no] as $arRow) {

                $date = $arRow['tdate'];
                $trans = $arRow['trans_num'];
                $charges = $arRow['charges'];
                $payment =  $arRow['payments'];

                $detail = $details[$card_no][$trans];

                if (strstr($detail[0],"Gazette Ad")) {
                    $gazette = true;
                }
                $lineitem = (count($detail)==1) ? $detail[0] : '(multiple items)';
                foreach ($detail as $line) {
                    if ($line == 'ARPAYMEN') {
                        $lineitem = 'Payment Received - Thank You';
                    }
                }

                $pdf->Cell($indent,8,'',0,0,'L');
                $pdf->Cell($columns[0],8,$date,0,0,'L');
                $pdf->Cell($columns[1],8,$trans,0,0,'L');
                $pdf->Cell($columns[2],8,'',0,0,'L');
                if ($payment > $charges) {
                    $pdf->Cell($columns[3],8,'$ ' . sprintf('%.2f',$payment-$charges),0,0,'L');
                } else {
                    $pdf->Cell($columns[3],8,'$ ' . sprintf('(%.2f)',abs($payment-$charges)),0,0,'L');
                }
                if ($pdf->GetY() > 245){
                    $pdf->AddPage();
                } else {
                    $pdf->Ln(5);
                }
                if (!empty($lineitem)){
                    $pdf->SetFontSize(10);
                    $pdf->Cell($indent+10,8,'',0,0,'L');
                    $pdf->Cell(60,8,$lineitem,0,0,'L');
                    if ($pdf->GetY() > 245) {
                        $pdf->AddPage();
                    } else {
                        $pdf->Ln(5);
                    }
                    $pdf->SetFontSize(12);
                }
            }

            $pdf->Ln(15);
            $pdf->Cell($indent,8,'');
            $pdf->SetFillColor(200);
            if ($balance >= 0) {
                $pdf->Cell(35,8,'Amount Due',0,0,'L',1);
            } else {
                $pdf->Cell(35,8,'Credit Balance',0,0,'L',1);
            }
            $pdf->Cell(25,8,'$ ' . sprintf("%.2f",$balance),0,0,'L');

            if ($gazette) {
                $pdf->SetLeftMargin(10);
                $pdf->Image('logo_bw.png',85,213, 25);

                $pdf->SetY(205);
                $pdf->Cell(0,8,'','B',1);
                $pdf->Ln(5);
    
                $pdf->Cell(30,5,'Whole Foods Co-op');
                $pdf->Cell(115,5,'');
                $pdf->Cell(20,5,'Invoice Date:',0,0,'R');
                $pdf->Cell(20,5,date("m/d/Y"),0,1,'L');
                $pdf->Cell(30,5,'610 East 4th Street');
                $pdf->Cell(115,5,'');
                $pdf->Cell(20,5,'Customer Number:',0,0,'R');
                $pdf->Cell(20,5,$card_no,0,1,'L');
                $pdf->Cell(30,5,'Duluth, MN 55805');
                $pdf->Cell(115,5,'');
                $pdf->Cell(20,5,'Invoice Total:',0,0,'R');
                $pdf->Cell(20,5,$balance,0,1,'L');

                $pdf->Ln(5);
                $pdf->Cell(10,10,trim($card_no),0);
                $pdf->Ln(5);
                $pdf->Cell(50,10,trim($primary['lastName']),0);
                $pdf->Ln(5);
                $pdf->Cell(80,10,$account['addressFirstLine'],0);
                if ($account['addressSecondLine']) {
                    $pdf->Ln(5);
                    $pdf->Cell(80,10,$account['addressSecondLine'],0);
                }
                $pdf->Ln(5);
                $pdf->Cell(90,10,$account['city'] . ', ' . $account['state'] . '   ' . $account['zip'],0);

                $pdf->SetXY(80,240);
                $pdf->SetFontSize(10);
                $pdf->MultiCell(110,6,"( ) Please continue this ad in the next issue.
( ) I would like to make some changes to my ad for the next issue.
( ) I do not wish to continue an ad in the next issue.
( ) I will contact you at a later date with my advertising decision.");
                $pdf->Ln(3);
    
                $pdf->SetFontSize(12);
                $pdf->Cell(0,8,'Please Return This Portion With Your Payment',0,0,'C');
            }
        }

        $pdf->Output('makeStatement.pdf','D');

        return false;
    }
}

FannieDispatch::conditionalExec();

