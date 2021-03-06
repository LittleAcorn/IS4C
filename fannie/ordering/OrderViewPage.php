<?php
/*******************************************************************************

    Copyright 2010 Whole Foods Co-op

    This file is part of CORE-POS.

    CORE-POS is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    CORE-POS is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/
include(dirname(__FILE__) . '/../config.php');
if (!class_exists('FannieAPI')) {
    include($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}

class OrderViewPage extends FannieRESTfulPage
{
    protected $header = 'View Order';
    protected $title = 'View Order';
    protected $must_authenticate = true;

    public function preprocess()
    {
        if (session_id() == '') {
            session_start();
        }

        $this->__routes[] = 'get<orderID>';
        $this->__routes[] = 'get<orderID><items>';
        $this->__routes[] = 'get<orderID><customer>';
        $this->__routes[] = 'post<orderID><memNum><upc><cases>';
        $this->__routes[] = 'post<orderID><transID><dept>';
        $this->__routes[] = 'post<orderID><transID><qty>';
        $this->__routes[] = 'post<orderID><transID><toggleStaff>';
        $this->__routes[] = 'post<orderID><transID><toggleMemType>';
        $this->__routes[] = 'post<orderID><togglePrint>';
        $this->__routes[] = 'post<orderID><noteDept><noteText><addr><addr2><city><state><zip><ph1><ph2><email>';
        $this->__routes[] = 'delete<orderID><transID>';
        $this->addRoute('post<orderID><description><srp><actual><qty><dept><unitPrice><vendor><transID><changed>');

        return parent::preprocess();
    }

    protected function post_orderID_transID_dept_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));
        $upP = $dbc->prepare('
            UPDATE PendingSpecialOrder
            SET department=?
            WHERE order_id=?
                AND trans_id=?'); 
        $upR = $dbc->execute($upP, array($this->dept, $this->orderID, $this->transID));

        return $this->get_orderID_items_handler();
    }

    protected function post_orderID_transID_qty_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));
        $upP = $dbc->prepare('
            UPDATE PendingSpecialOrder
            SET quantity=?
            WHERE order_id=?
                AND trans_id=?'); 
        $upR = $dbc->execute($upP, array($this->qty, $this->orderID, $this->transID));
        $this->reprice($this->orderID, $this->transID);

        return $this->get_orderID_items_handler();
    }

    protected function post_orderID_description_srp_actual_qty_dept_unitPrice_vendor_transID_changed_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));
        $basicP = $dbc->prepare('
            UPDATE PendingSpecialOrder
            SET description=?,
                department=?,
                mixMatch=?,
                total=?,
                unitPrice=?,
                quantity=?
            WHERE order_id=?
                AND trans_id=?
        ');
        $basicR = $dbc->execute($basicP, array(
            $this->description,
            $this->dept,
            $this->vendor,
            $this->actual,
            $this->unit,
            $this->qty,
            $this->orderID,
            $this->transID,
        ));

        if ($this->changed == 'srp' || $this->changed == 'qty' || $this->changed == 'unit') {
            $info = $this->reprice($this->orderID, $this->transID, ($this->changed == 'srp' ? $this->srp : false));
        } else {
            $info = array('regPrice' => $this->srp, 'total' => $this->actual);
        }

        $fetchP = $dbc->prepare("SELECT ROUND(100*((regPrice-total)/regPrice),0)
            FROM PendingSpecialOrder WHERE trans_id=? AND order_id=?");
        $info['discount'] = $dbc->getValue($fetchP, array($this->transID, $this->orderID));
        echo json_encode($info);

        return false;
    }

    protected function post_orderID_togglePrint_handler()
    {
        $user = $this->current_user;
        $cachepath = sys_get_temp_dir()."/ordercache/";
        $prints = unserialize(file_get_contents("{$cachepath}{$user}.prints"));
        if (isset($prints[$this->orderID])) {
            unset($prints[$this->orderID]);
        } else {
            $prints[$this->orderID] = array();
        }
        $fptr = fopen("{$cachepath}{$user}.prints",'w');
        fwrite($fptr,serialize($prints));
        fclose($fptr);

        return false;
    }

    protected function post_orderID_transID_toggleStaff_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));

        $upP = $dbc->prepare('
            UPDATE PendingSpecialOrder 
            SET memType = (staff+1)%2
            WHERE order_id=? 
                AND trans_id=?');
        $dbc->execute($upP, array($this->orderID, $this->transID));

        return false;
    }

    protected function post_orderID_transID_toggleMemType_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));

        $upP = $dbc->prepare('
            UPDATE PendingSpecialOrder 
            SET memType = (memType+1)%2
            WHERE order_id=? 
                AND trans_id=?');
        $dbc->execute($upP, array($this->orderID, $this->transID));

        return false;
    }

    protected function delete_orderID_transID_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));
        $delP = $dbc->prepare('
            DELETE FROM PendingSpecialOrder
            WHERE order_id=?
                AND trans_id=?');
        $delR = $dbc->execute($delP, array($this->orderID, $this->transID));

        return $this->get_orderID_items_handler();
    }

    protected function post_orderID_memNum_upc_cases_handler()
    {
        if (is_numeric($this->cases)) {
            $this->cases = (int)$this->cases;
        } else {
            $this->cases = 1;
        }
        $result = $this->addUPC($this->orderID, $this->memNum, $this->upc, $this->cases);
        if (!is_numeric($this->upc)) {
            echo $this->getDeptForm($this->orderID, $result[1], $result[2]);
        } elseif ($result[0] === false) {
            return $this->get_orderID_items_handler();
        } else {
            echo $this->getQtyForm($this->orderID, $result[0], $result[1], $result[2]);
        }

        return false;
    }

    protected function post_orderID_noteDept_noteText_addr_addr2_city_state_zip_ph1_ph2_email_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));

        $street = $this->addr;
        if (!empty($this->addr2)) {
            $street .= "\n" . $this->addr2;
        }

        $soModel = new SpecialOrdersModel($dbc);
        $soModel->specialOrderID($this->orderID);
        $soModel->noteSuperID($this->noteDept);
        $soModel->notes($this->noteText);
        $soModel->street($street);
        $soModel->city($this->city);
        $soModel->state($this->state);
        $soModel->zip($this->zip);
        $soModel->phone($this->ph1);
        $soModel->altPhone($this->ph2);
        $soModel->email($this->email);

        if (FormLib::get('fn', false) !== false) {
            $soModel->firstName(FormLib::get('fn'));
        }
        if (FormLib::get('ln', false) !== false) {
            $soModel->lastName(FormLib::get('ln'));
        }
        $json = array();
        $json['saved'] = $soModel->save() ? true : false;
        echo json_encode($json);

        return false;
    }

    protected function get_orderID_customer_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();
        $orderID = $this->orderID;
        try {
            $memNum = $this->form->memNum;
        } catch (Exception $ex) {
            $memNum = '0';
        }
        $canEdit = FannieAuth::validateUserQuiet('ordering_edit');

        if (empty($orderID)) {
            $orderID = $this->createEmptyOrder();
        }

        $names = array();
        $personNum = 1;
        $status_row = array(
            'Type' => 'REG',
            'status' => ''
        );

        $dbc->selectDB($this->config->get('TRANS_DB'));
        $orderModel = new SpecialOrdersModel($dbc);
        $orderModel->specialOrderID($orderID);
        $orderModel->load();
        $dbc->selectDB($this->config->get('OP_DB'));

        // detect member UPC entry
        if ($memNum > 9999999) {
            $cards = new MemberCardsModel($dbc);
            $cards->upc(BarcodeLib::padUPC($memNum));
            $memNum = '';
            foreach ($cards->find() as $c) {
                $memNum = $c->card_no();
                break;
            }
        }

        // look up member id if applicable
        if ($memNum === "0") {
            $findMem = $dbc->prepare("SELECT card_no,voided FROM {$TRANS}PendingSpecialOrder WHERE order_id=?");
            $memR = $dbc->execute($findMem, array($orderID));
            if ($dbc->numRows($memR) > 0) {
                $memW = $dbc->fetchRow($memR);
                $memNum = $memW['card_no'];
                $personNum = $memW['voided'];
            }
        } elseif ($memNum == "") {
            $prep = $dbc->prepare("UPDATE {$TRANS}PendingSpecialOrder SET card_no=?,voided=0
                WHERE order_id=?");
            $dbc->execute($prep,array(0,$orderID));
        } else {
            $prep = $dbc->prepare("UPDATE {$TRANS}PendingSpecialOrder SET card_no=?
                WHERE order_id=?");
            $dbc->execute($prep,array($memNum,$orderID));

            // clear contact fields if member number changed
            // so that defaults are reloaded from meminfo
            $dbc->selectDB($this->config->get('TRANS_DB'));
            $orderModel->street('');
            $orderModel->phone('');
            $orderModel->save();
            $orderModel->specialOrderID($orderID);
            $orderModel->load();
            $dbc->selectDB($this->config->get('OP_DB'));

            // look up personnum, correct if it hasn't been set
            $pendQ = $dbc->prepare_statement("SELECT voided FROM {$TRANS}PendingSpecialOrder
                WHERE order_id=?");
            $personNum = $dbc->getValue($pendQ,array($orderID));
            if ($personNum == 0) {
                $personNum = 1;
                $upP = $dbc->prepare_statement("UPDATE {$TRANS}PendingSpecialOrder SET voided=?
                    WHERE order_id=?");
                $upR = $dbc->exec_statement($upP,array($personNum,$orderID));
            }
        }

        if ($memNum != 0) {
            $custdata = new CustdataModel($dbc);
            $custdata->CardNo($memNum);
            foreach ($custdata->find('personNum') as $c) {
                $names[$c->personNum()] = array($c->FirstName(), $c->LastName());
            }

            // load member contact info into order
            // on first go so it can be edited separately
            $current_street = $orderModel->street();
            $current_phone = $orderModel->phone();
            if (empty($current_street) && empty($current_phone)) {
                $contactQ = $dbc->prepare_statement("SELECT street,city,state,zip,phone,email_1,email_2
                        FROM meminfo WHERE card_no=?");
                $contactR = $dbc->exec_statement($contactQ, array($memNum));
                if ($dbc->num_rows($contactR) > 0) {
                    $contact_row = $dbc->fetch_row($contactR);

                    $dbc->selectDB($this->config->get('TRANS_DB'));
                    $orderModel->street($contact_row['street']);
                    $orderModel->city($contact_row['city']);
                    $orderModel->state($contact_row['state']);
                    $orderModel->zip($contact_row['zip']);
                    $orderModel->phone($contact_row['phone']);
                    $orderModel->altPhone($contact_row['email_2']);
                    $orderModel->email($contact_row['email_1']);
                    $orderModel->save();
                    $orderModel->specialOrderID($orderID);
                    $orderModel->load();
                
                    $dbc->selectDB($this->config->get('OP_DB'));
                }
            }

            if ($custdata->load()) {
                $status_row['Type'] = $custdata->Type();
                if ($status_row['Type'] == 'INACT') {
                    $status_row['status'] = 'Inactive';
                } elseif ($status_row['Type'] == 'INACT2') {
                    $status_row['status'] = 'Inactive';
                } elseif ($status_row['Type'] == 'TERM') {
                    $status_row['status'] = 'Terminated';
                }
            }
        } 

        $prep = $dbc->prepare_statement("SELECT entry_date FROM {$TRANS}SpecialOrderHistory 
                WHERE order_id=? AND entry_type='CONFIRMED'");
        $confirm_date = $dbc->getValue($prep, array($orderID));

        $callback = 2;
        $user = 'Unknown';
        $orderDate = "";
        $prep = $dbc->prepare_statement("SELECT datetime,numflag,mixMatch FROM 
                {$TRANS}PendingSpecialOrder WHERE order_id=? AND trans_id=0");
        $res = $dbc->exec_statement($prep, array($orderID));
        if ($dbc->num_rows($res) > 0) {
            list($orderDate,$callback,$user) = $dbc->fetch_row($res);
        }

        $status = array(
            0 => "New, No Call",
            3 => "New, Call",
            1 => "Called/waiting",
            2 => "Pending",
            4 => "Placed",
            5 => "Arrived"
        );
        $order_status = $orderModel->statusFlag();

        $ret = "";
        $ret .= sprintf('<input type="hidden" id="orderID" value="%d" />',$orderID);
        $ret .= '<div class="row form-inline"><div class="col-sm-4 text-left">';
        $ret .= sprintf('<b>Owner Number</b>: <input type="text" size="6"
                id="memNum" value="%s" class="form-control price-field input-sm" 
                />',($memNum==0?'':$memNum));
        $ret .= '<br />';
        $ret .= '<b>Owner</b>: '.($status_row['Type']=='PC'?'Yes':'No');
        $ret .= sprintf('<input type="hidden" id="isMember" value="%s" />',
                $status_row['Type']);
        $ret .= '<br />';
        if (!empty($status_row['status'])) {
            $ret .= '<b>Account status</b>: '.$status_row['status'];
            $ret .= '<br />';
        }
        $ret .= '</div><div class="col-sm-4 text-center">';

        if ($canEdit) {
            $ret .= '<b>Status</b>: ';
            $ret .= '<select id="orderStatus" class="form-control input-sm">';
            foreach($status as $k => $v) {
                $ret .= sprintf('<option %s value="%d">%s</option>',
                            ($k == $order_status ? 'selected' : ''),
                            $k, $v);
            }
            $ret .= '</select><p />';
        }
        $ret .= '<b>Store</b>: ';
        $ret .= '<select id="orderStore" class="form-control input-sm">';
        $ret .= '<option value="0">Choose...</option>';
        $stores = new StoresModel($dbc);
        $ret .= $stores->toOptions($orderModel->storeID());
        $ret .= '</select>';
        $ret .= '</div><div class="col-sm-4 text-right">';

        $ret .= "<a href=\"\" class=\"btn btn-default btn-sm done-btn\">Done</a>";
        $username = FannieAuth::checkLogin();
        $prints = array();
        $cachepath = sys_get_temp_dir()."/ordercache/";
        if (file_exists("{$cachepath}{$username}.prints")) {
            $prints = unserialize(file_get_contents("{$cachepath}{$username}.prints"));
        } else {
            $fptr = fopen("{$cachepath}{$username}.prints",'w');
            fwrite($fptr,serialize($prints));
            fclose($fptr);
        }
        $ret .= sprintf('<br />Queue tags <input type="checkbox" %s class="print-cb" />',
                (isset($prints[$orderID])?'checked':''),
                $username,$orderID
            );
        $ret .= sprintf('<br /><a href="tagpdf.php?oids[]=%d" target="_tags%d">Print Now</a>',
                $orderID,$orderID);
        $ret .= '</div></div>';

        $extra = "";    
        $extra .= '<div class="row"><div class="col-sm-6 text-left">';
        $extra .= "<b>Taken by</b>: ".$user."<br />";
        $extra .= "<b>On</b>: ".date("M j, Y g:ia",strtotime($orderDate))."<br />";
        $extra .= '</div><div class="col-sm-6 text-right form-inline">';
        $extra .= '<b>Call to Confirm</b>: ';
        $extra .= '<select id="ctcselect" class="form-control input-sm">'; 
        $extra .= '<option value="2"></option>';
        if ($callback == 1) {
            $extra .= '<option value="1" selected>Yes</option>';    
            $extra .= '<option value="0">No</option>';  
        } else if ($callback == 0) {
            $extra .= '<option value="1">Yes</option>'; 
            $extra .= '<option value="0" selected>No</option>'; 
        } else {
            $extra .= '<option value="1">Yes</option>'; 
            $extra .= '<option value="0">No</option>';  
        }
        $extra .= '</select><br />';    
        $extra .= '<span id="confDateSpan">'.(!empty($confirm_date)?'Confirmed '.$confirm_date:'Not confirmed')."</span> ";
        $extra .= '<input type="checkbox" id="confirm-date" ';
        if (!empty($confirm_date)) $extra .= "checked";
        $extra .= ' /><br />';

        $extra .= "<a href=\"\" class=\"btn btn-default btn-sm done-btn\">Done</a>";
        $extra .= '</div></div>';

        $ret .= '<table class="table table-bordered">';

        // names
        if (empty($names)) {
            $ret .= sprintf('<tr><th>First Name</th><td>
                    <input type="text" id="t_firstName" name="fn"
                    class="form-control input-sm conact-field"
                    value="%s" 
                    /></td>',$orderModel->firstName());
            $ret .= sprintf('<th>Last Name</th><td><input 
                    type="text" id="t_lastName" value="%s" name="ln"
                    class="form-control input-sm contact-field"
                    /></td>',
                    $orderModel->lastName());
        } else {
            $ret .= '<tr><th>Name</th><td colspan="2"><select id="s_personNum"
                class="form-control input-sm">';
            foreach($names as $p=>$n) {
                $ret .= sprintf('<option value="%d" %s>%s %s</option>',
                    $p,($p==$personNum?'selected':''),
                    $n[0],$n[1]);
            }
            $ret .= '</select></td>';
            $ret .= '<td>&nbsp;</td>';
        }
        $ret .= '<td colspan="4" class="form-inline">For Department:
            <select id="nDept" class="form-control input-sm contact-field" 
                name="noteDept">
            <option value="0">Choose...</option>';
        $superQ = $dbc->prepare_statement("select superID,super_name from MasterSuperDepts
            where superID > 0
            group by superID,super_name
            order by super_name");
        $superR = $dbc->exec_statement($superQ);
        while($superW = $dbc->fetch_row($superR)) {
            $ret .= sprintf('<option value="%d" %s>%s</option>',
                $superW['superID'],
                ($superW['superID']==$orderModel->noteSuperID()?'selected':''),
                $superW['super_name']);
        }
        $ret .= "</select></td></tr>";

        // address
        $street = $orderModel->street();
        $street2 = '';
        if(strstr($street,"\n")) {
            list($street, $street2) = explode("\n", $street, 2);
        }

        $ret .= sprintf('
            <tr>
                <th>Address</th>
                <td>
                    <input type="text" id="t_addr1" value="%s" 
                        class="form-control input-sm contact-field"
                        name="addr" />
                </td>
                <th>E-mail</th>
                <td>
                    <input type="text" id="t_email" value="%s" 
                        class="form-control input-sm contact-field"
                        name="email" />
                </td>
                <td rowspan="2" colspan="4">
                    <textarea id="nText" rows="5" cols="25" 
                        class="form-control input-sm contact-field" name="noteText"
                        >%s</textarea>
                </td>
            </tr>
            <tr>
                <th>Addr (2)</th>
                <td>
                    <input type="text" id="t_addr2" value="%s" 
                        class="form-control input-sm contact-field"
                        name="addr2" />
                </td>
                <th>City</th>
                <td>
                    <input type="text" id="t_city" name="city"
                        class="form-control input-sm contact-field"
                        value="%s" size="10" />
                </td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>
                    <input type="text" id="t_ph1" name="ph1"
                        class="form-control input-sm contact-field"
                        value="%s" />
                </td>
                <th>Alt. Phone</th>
                <td>
                    <input type="text" id="t_ph2" value="%s" name="ph2"
                        class="form-control input-sm contact-field" />
                </td>
                <th>State</th>
                <td>
                    <input type="text" id="t_state" value="%s" size="2" 
                        class="form-control input-sm contact-field"
                        name="state"  />
                </td>
                <th>Zip</th>
                <td>
                    <input type="text" id="t_zip" value="%s" size="5" 
                        class="form-control input-sm contact-field"
                        name="zip" />
                </td>
            </tr>',
            $street,
            $orderModel->email(),
            $orderModel->notes(),
            $street2, 
            $orderModel->city(), 
            $orderModel->phone(), 
            $orderModel->altPhone(), 
            $orderModel->state(), 
            $orderModel->zip() 
        );

        $ret .= '</table>';

        echo json_encode(array('customer'=>$ret, 'footer'=>$extra));

        return false;
    }

    private function addUPC($orderID, $memNum, $upc, $num_cases=1)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();

        $ins_array = $this->genericRow($orderID);
        $ins_array['upc'] = "$upc";
        $ins_array['card_no'] = "$memNum";
        $ins_array['trans_type'] = "I";
        $ins_array['ItemQtty'] = $num_cases;

        if (!class_exists('OrderItemLib')) {
            include(dirname(__FILE__) . '/OrderItemLib.php');
        }

        $mempricing = OrderItemLib::memPricing($memNum);

        $item = OrderItemLib::getItem($upc);
        $qtyReq = OrderItemLib::manualQuantityRequired($item);
        $item['department'] = OrderItemLib::mapDepartment($item['department']);
        if ($qtyReq !== false) {
            $item['caseSize'] = $qtyReq;
        }
        $unitPrice = OrderItemLib::getUnitPrice($item, $mempricing);
        $casePrice = OrderItemLib::getCasePrice($item, $mempricing);

        $ins_array['upc'] = $item['upc'];
        $ins_array['quantity'] = $item['caseSize'];
        $ins_array['mixMatch'] = substr($item['vendorName'], 0, 26);
        $ins_array['description'] = substr($item['description'], 0, 32) . ' SO';
        $ins_array['department'] = $item['department'];
        $ins_array['discountable'] = $item['discountable'];
        $ins_array['discounttype'] = $item['discounttype'];
        $ins_array['cost'] = $item['cost'];
        $ins_array['unitPrice'] = $unitPrice;
        $ins_array['total'] = $casePrice * $num_cases;
        $ins_array['regPrice'] = $item['normal_price'] * $item['caseSize'] * $num_cases;

        $tidP = $dbc->prepare_statement("SELECT MAX(trans_id),MAX(voided),MAX(numflag) 
                FROM {$TRANS}PendingSpecialOrder WHERE order_id=?");
        $tidR = $dbc->exec_statement($tidP,array($orderID));
        $tidW = $dbc->fetch_row($tidR);
        $ins_array['trans_id'] = $tidW[0]+1;
        $ins_array['voided'] = $tidW[1];
        $ins_array['numflag'] = $tidW[2];

        $dbc->smart_insert("{$TRANS}PendingSpecialOrder",$ins_array);

        return array($qtyReq,$ins_array['trans_id'],$ins_array['description']);
    }

    private function createEmptyOrder()
    {
        global $FANNIE_OP_DB,$TRANS,$FANNIE_SERVER_DBMS, $FANNIE_TRANS_DB;
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();
        $user = FannieAuth::checkLogin();
        $orderID = 1;
        $values = ($this->config->get('SERVER_DBMS') != "MSSQL" ? "VALUES()" : "DEFAULT VALUES");
        $dbc->query('INSERT ' . $TRANS . 'SpecialOrders ' . $values);
        $orderID = $dbc->insert_id();

        /**
          @deprecated 24Apr14
          New SpecialOrders table is standard now
        */
        if ($dbc->table_exists($TRANS . 'SpecialOrderID')) {
            $soP = $dbc->prepare('INSERT INTO ' . $TRANS . 'SpecialOrderID (id) VALUES (?)');
            $soR = $dbc->execute($soP, array($orderID));
        }

        $ins_array = $this->genericRow($orderID);
        $ins_array['numflag'] = 2;
        $ins_array['mixMatch'] = $user;
        $dbc->smart_insert("{$TRANS}PendingSpecialOrder",$ins_array);

        $note_vals = array(
            'order_id'=>$orderID,
            'notes'=>"",
            'superID'=>0
        );

        $status_vals = array(
            'order_id'=>$orderID,
            'status_flag'=>3,
            'sub_status'=>time()
        );

        $dbc->selectDB($this->config->get('TRANS_DB'));
        $s_order = new SpecialOrdersModel($dbc);
        $s_order->specialOrderID($orderID);
        $s_order->statusFlag($status_vals['status_flag']);
        $s_order->subStatus($status_vals['sub_status']);
        $s_order->notes(trim($note_vals['notes'],"'"));
        $s_order->noteSuperID($note_vals['superID']);
        $s_order->save();
        $dbc->selectDB($this->config->get('TRANS_DB')); // switch back to previous

        if ($dbc->table_exists($TRANS . 'SpecialOrderStatus')) {
            $dbc->smart_insert("{$TRANS}SpecialOrderStatus",$status_vals);
        }

        $this->createContactRow($orderID);

        return $orderID;
    }

    private function genericRow($orderID)
    {
        return array(
        'order_id'=>$orderID,
        'datetime'=>date('Y-m-d H:i:s'),
        'emp_no'=>1001,
        'register_no'=>30,
        'trans_no'=>$orderID,
        'upc'=>'0',
        'description'=>"SPECIAL ORDER",
        'trans_type'=>"C",
        'trans_subtype'=>"",
        'trans_status'=>"",
        'department'=>0,
        'quantity'=>0,
        'scale'=>0,
        'cost'=>0,
        'unitPrice'=>0,
        'total'=>0,
        'regPrice'=>0,
        'tax'=>0,
        'foodstamp'=>0,
        'discount'=>0,
        'memDiscount'=>0,
        'discountable'=>1,
        'discounttype'=>0,
        'voided'=>0,
        'percentDiscount'=>0,
        'ItemQtty'=>0,
        'volDiscType'=>0,
        'volume'=>0,
        'VolSpecial'=>0,
        'mixMatch'=>0,
        'matched'=>0,
        'memType'=>0,
        'staff'=>0,
        'numflag'=>0,
        'charflag'=>"",   
        'card_no'=>0,
        'trans_id'=>0
        );
    }

    private function createContactRow($orderID)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('TRANS_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();

        $so_order = new SpecialOrdersModel($dbc);
        $so_order->specialOrderID($orderID);
        $so_order->firstName('');
        $so_order->lastName('');
        $so_order->street('');
        $so_order->city('');
        $so_order->state('');
        $so_order->zip('');
        $so_order->phone('');
        $so_order->altPhone('');
        $so_order->email('');
        $so_order->save();

        $dbc->selectDB($this->config->get('OP_DB'));
    }

    protected function get_orderID_items_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        
        $ret = <<<HTML
<form> 
<div class="form-inline">
    <div class="input-group">
        <span class="input-group-addon">UPC</span> 
        <input type="text" id="newupc" class="form-control input-sm" maxlength="35" />
    </div>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <div class="input-group">
        <span class="input-group-addon">Cases</span> 
        <input id="newcases" maxlength="2" value="1" size="3" class="form-control input-sm" />
    </div>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <button type="submit" class="btn btn-default btn-sm">Add Item</button>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <button type="button" class="btn btn-default btn-sm btn-search">Search</button>
</div>
</form>
<p />
HTML;

        if (FannieAuth::validateUserQuiet('ordering_edit')) {
            $ret .= $this->editableItemList($this->orderID);
        } else {
            $ret .= itemList($this->orderID);
        }

        $ret .= '<p />';
        $ret .= '<b><a href="" onclick="$(\'#manualclosebuttons\').toggle();return false;">Manually close order</a></b>';
        $ret .= sprintf('<span id="manualclosebuttons" class="collapse"> as:
                <a href="" class="btn btn-default close-order-btn" data-close="7">Completed</a>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                <a href="" class="btn btn-default close-order-btn" data-close="8">Canceled</a>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                <a href="" class="btn btn-default close-order-btn" data-close="9">Inquiry</a>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<br />
                <div class="alert alert-danger">Closing an order means slips for these
                items will no longer scan at the registers</div></span>',
                $this->orderID,$this->orderID,$this->orderID);

        echo $ret;

        return false;
    }

    private function editableItemList($orderID)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();

        $deptP = $dbc->prepare_statement("SELECT dept_no,dept_name FROM departments order by dept_no");
        $deptR = $dbc->exec_statement($deptP);
        $depts = array(0=>'Unassigned');
        while($deptW = $dbc->fetch_row($deptR)) {
            $depts[$deptW['dept_no']] = $deptW['dept_name'];
        }

        $ret = '<table class="table table-bordered table-striped">';
        $ret .= '<tr><th>UPC</th><th>SKU</th><th>Description</th><th>Cases</th><th>SRP</th><th>Actual</th><th>Qty</th><th>Dept</th><th>&nbsp;</th></tr>';
        $prep = $dbc->prepare_statement("SELECT o.upc,o.description,total,quantity,department,
            v.sku,ItemQtty,regPrice,o.discounttype,o.charflag,o.mixMatch,
            o.trans_id,o.unitPrice,o.memType,o.staff
            FROM {$TRANS}PendingSpecialOrder as o
            left join vendorItems as v on o.upc=v.upc AND vendorID=1
            WHERE order_id=? AND trans_type='I' 
            ORDER BY trans_id DESC");
        $res = $dbc->exec_statement($prep, array($orderID));
        $num_rows = $dbc->num_rows($res);
        $prev_id = 0;
        while ($row = $dbc->fetch_row($res)) {
            if ($row['trans_id'] == $prev_id) continue;
            $ret .= sprintf('
                    <tbody>
                    <tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td><input class="form-control input-sm item-field" name="description"
                        value="%s" /></td>
                    <td>%d</td>
                    <td><input size="5" class="form-control input-sm price-field item-field" id="srp%d" 
                        name="srp" value="%.2f" /></td>
                    <td><input size="5" class="form-control input-sm price-field item-field" id="act%d" 
                        value="%.2f" name="actual" /></td>
                    <td><input size="4" class="form-control input-sm price-field item-field" 
                        value="%.2f" name="qty" /></td>
                    <td><select class="form-control input-sm editDept item-field" 
                        name="dept">',
                    $row['upc'],
                    (!empty($row['sku'])?$row['sku']:'&nbsp;'),
                    $row['description'],
                    $row['ItemQtty'],
                    $row['trans_id'],$row['regPrice'],
                    $row['trans_id'],$row['total'],
                    $row['quantity']
                );
            foreach($depts as $id=>$name) {
                $ret .= sprintf('<option value="%d" %s>%d %s</option>',
                    $id,
                    ($id==$row['department']?'selected':''),
                    $id,$name);
            }
            $ret .= sprintf('</select></td>
                    <td><a href="" data-order="%d" data-trans="%d" 
                        class="btn btn-danger btn-xs btn-delete">%s</a></td>
                    </tr>',
                    $orderID,$row['trans_id'],
                    \COREPOS\Fannie\API\lib\FannieUI::deleteIcon()
            );
            $ret .= '<tr>';
            $ret .= sprintf('<td colspan="2" align="right" class="form-inline">Unit Price: 
                <input type="text" size="4" value="%.2f" id="unitp%d" name="unitPrice"
                class="form-control input-sm price-field item-field" /></td>',
                $row['unitPrice'],$row['trans_id']);
            $ret .= sprintf('<td class="form-inline">Supplier: <input type="text" value="%s" size="12" 
                    class="form-control input-sm item-field" name="vendor"
                    maxlength="26" 
                    /></td>',$row['mixMatch']);
            $ret .= '<td>Discount</td>';
            if ($row['discounttype'] == 1 || $row['discounttype'] == 2) {
                $ret .= '<td class="disc-percent" id="discPercent'.$row['trans_id'].'">Sale</td>';
            } else if ($row['regPrice'] != $row['total']) {
                $ret .= sprintf('<td class="disc-percent" id="discPercent%d">%d%%</td>',$row['upc'],
                    round(100*(($row['regPrice']-$row['total'])/$row['regPrice'])));
            } else {
                $ret .= '<td class="disc-percent" id="discPercent'.$row['upc'].'">0%</td>';
            }
            $ret .= sprintf('<td colspan="2">Printed: %s</td>',
                    ($row['charflag']=='P'?'Yes':'No'));
            if ($num_rows > 1) {
                $ret .= sprintf('<td colspan="2"><a href="" class="btn btn-default btn-sm"
                    onclick="orderView.doSplit(%d,%d);return false;">Split Item to New Order</a><br />
                    O <input type="checkbox" class="itemChkO" %s data-order="%d" data-trans="%d" />&nbsp;&nbsp;&nbsp;&nbsp;
                    A <input type="checkbox" class="itemChkA" %s data-order="%d" data-trans="%d" />
                    </td>',
                    $orderID,$row['trans_id'],
                    ($row['memType']>0?'checked':''),$orderID,$row['trans_id'],
                    ($row['staff']>0?'checked':''),$orderID,$row['trans_id']);
            } else {
                $ret .= '<td colspan="2"></td>';
            }
            $ret .= '</tr>';
            $ret .= '<tr><td class="small" colspan="9"><span style="font-size:1;">&nbsp;</span>';
            $ret .= '<input type="hidden" name="transID" class="item-field" value="' . $row['trans_id'] . '" /></td></tr>';
            $ret .= '</tbody>';
            $prev_id=$row['trans_id'];
        }
        $ret .= '</table>';

        return $ret;
    }

    private function getQtyForm($orderID,$default,$transID,$description)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $ret = '<i>This item ('.$description.') requires a quantity</i><br />';
        $ret .= "<form data-order=\"$orderID\" data-trans=\"$transID\">";
        $ret .= '<div class="form-inline">';
        $ret .= '<label>Qty</label>: <input type="text" id="newqty" 
            class="form-control input-sm" value="'.$default.'" maxlength="3" size="4" />';
        $ret .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        $ret .= '<button type="submit" class="btn btn-default">Enter Qty</button>';
        $ret .= '</div>';
        $ret .= '</form>';

        return $ret;
    }

    private function getDeptForm($orderID,$transID,$description)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();
        $ret = '<i>This item ('.$description.') requires a department</i><br />';
        $ret .= "<form data-order=\"$orderID\" data-trans=\"$transID\">";
        $ret .= '<div class="form-inline">';
        $ret .= '<select id="newdept" class="form-control">';
        $prep = $dbc->prepare_statement("select super_name,
            CASE WHEN MIN(map_to) IS NULL THEN MIN(m.dept_ID) ELSE MIN(map_to) END
            from MasterSuperDepts
            as m left join {$TRANS}SpecialOrderDeptMap as s
            on m.dept_ID=s.dept_ID
            where m.superID > 0
            group by super_name ORDER BY super_name");
        $res = $dbc->exec_statement($prep);
        while ($row = $dbc->fetch_row($res)) {
            $ret .= sprintf('<option value="%d">%s</option>',$row[1],$row[0]);
        }
        $ret .= "</select>";
        $ret .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        $ret .= '<button type="submit" class="btn btn-default">Enter Dept</button>';
        $ret .= '</div>';
        $ret .= '</form>';
        
        return $ret;
    }

    private function reprice($oid,$tid,$reg=false)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $TRANS = $this->config->get('TRANS_DB') . $dbc->sep();

        $query = $dbc->prepare_statement("SELECT o.unitPrice,o.itemQtty,o.quantity,o.discounttype,
            c.type,c.memType,o.regPrice,o.total,o.discountable,o.cost,o.card_no
            FROM {$TRANS}PendingSpecialOrder AS o LEFT JOIN custdata AS c ON
            o.card_no=c.CardNo AND c.personNum=1
            WHERE order_id=? AND trans_id=?");
        $response = $dbc->exec_statement($query, array($oid,$tid));
        $row = $dbc->fetch_row($response);

        $regPrice = $row['itemQtty']*$row['quantity']*$row['unitPrice'];
        if ($reg !== false) {
            $regPrice = $reg;
        }
        $total = $regPrice;
        if (!class_exists('OrderItemLib')) {
            include(dirname(__FILE__) . '/OrderItemLib.php');
        }
        if ($row['discountable'] != 0 && $row['discounttype'] == 0) {
            $mempricing = OrderItemLib::memPricing($row['card_no']);
            // create fake item to re-apply rules for marking up/down
            $item = array('normal_price' => $regPrice, 
                'cost'=>$row['cost']*$row['itemQtty']*$row['quantity']);
            $total = OrderItemLib::markUpOrDown($item, $mempricing);
        }

        if ($row['unitPrice'] == 0 || $row['quantity'] == 0) {
            $regPrice = $row['regPrice'];
            $total = $row['total'];
        }

        $query = $dbc->prepare_statement("UPDATE {$TRANS}PendingSpecialOrder SET
                total=?,regPrice=?
                WHERE order_id=? AND trans_id=?");
        $dbc->exec_statement($query, array($total,$regPrice,$oid,$tid));

        return array(
            'regPrice'=>sprintf("%.2f",$regPrice),
            'total'=>sprintf("%.2f",$total)
        );
    }

    protected function get_handler()
    {
        $orderID = $this->createEmptyOrder();

        return filter_input(INPUT_SERVER, 'PHP_SELF') . '?orderID=' . $orderID;
    }

    // this shouldn't occur unless something goes wonky creating the new order
    protected function get_view()
    {
        return '<div class="alert alert-danger">No Order Specified</div>';
    }

    protected function get_orderID_view()
    {
        $orderID = $this->orderID;
        $refer = filter_input(INPUT_SERVER, 'HTTP_REFERER');
        $return_path = ($refer && strstr($refer,'fannie/ordering/NewSpecialOrdersPage.php')) ? $refer : '';
        if (!empty($return_path)) {
            $_SESSION['specialOrderRedirect'] = $return_path;
        } elseif (isset($_SESSION['specialOrderRedirect'])) {
            $return_path = $_SESSION['specialOrderRedirect'];
        } else {
            $return_path = $this->config->get('URL') . "ordering/";
        }
        $ret = sprintf("<input type=hidden id=redirectURL value=\"%s\" />",$return_path);

        $prev = -1;
        $next = -1;
        $found = False;
        $cachepath = sys_get_temp_dir()."/ordercache/";
        $cachekey = FormLib::get('k');
        if ($cachekey && file_exists($cachepath.$cachekey)) {
            $fptr = fopen($cachepath.$cachekey,'r');
            while (($buffer = fgets($fptr, 4096)) !== false) {
                if ((int)$buffer == $orderID) $found = True;
                else if (!$found) $prev = (int)$buffer;
                else if ($found) {
                    $next = (int)$buffer;
                    break;
                }
            }
            fclose($fptr);

            $ret .= '<div class="row">
                <div class="col-sm-6 text-left">';
            if ($prev == -1) {
                $ret .= '<span class="glyphicon glyphicon-chevron-left"></span>Prev';
            } else {
                $ret .= sprintf('<a href="?orderID=%d&k=%s" class="btn btn-default btn-xs">
                    <span class="glyphicon glyphicon-chevron-left"></span>Prev</a>',$prev,$cachekey);
            }
            $ret .= '</div><div class="col-sm-6 text-right">';
            if ($next == -1) {
                $ret .= '<span class="glyphicon glyphicon-chevron-right"></span>Next';
            } else {
                $ret .= sprintf('<a href="?orderID=%d&k=%s" class="btn btn-default btn-xs">
                    <span class="glyphicon glyphicon-chevron-right"></span>Next</a>',$next,$cachekey);
            }
            $ret .= '</div></div>';
            $ret .= '<p />';
        }

        $ret .= <<<HTML
<div class="panel panel-default">
    <div class="panel-heading">Customer Information</div>
    <div class="panel-body" id="customerDiv"></div>
</div>
<div class="panel panel-default">
    <div class="panel-heading">Order Items</div>
    <div class="panel-body" id="itemDiv"></div>
</div>
<div id="footerDiv"></div>
HTML;
        $ret .= sprintf("<input type=hidden value=\"%d\" id=\"init_oid\" />",$orderID);

        $this->addScript('orderview.js');

        return $ret;
    }

    public function unitTest($phpunit)
    {
        if (!class_exists('SpecialOrderTests', false)) {
            include(dirname(__FILE__) . '/SpecialOrderTests.php');
        }
        $tester = new SpecialOrderTests($this->connection, $this->config, $this->logger);
        $tester->testCreateOrder($this, $phpunit);
        $tester->testOrderView($this, $phpunit);
        $tester->testSetCustomer($this, $phpunit);
        $tester->testAddItem($this, $phpunit);
        $tester->testDeleteItem($this, $phpunit);
        $tester->testEditCustomer($this, $phpunit);
    }
}

FannieDispatch::conditionalExec();

