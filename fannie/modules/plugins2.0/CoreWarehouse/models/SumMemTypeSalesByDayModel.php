<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of IT CORE.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

if (!class_exists('CoreWarehouseModel')) {
    include_once(dirname(__FILE__).'/CoreWarehouseModel.php');
}

class SumMemTypeSalesByDayModel extends CoreWarehouseModel {

    protected $name = 'sumMemTypeSalesByDay';
    
    protected $columns = array(
    'date_id' => array('type'=>'INT','primary_key'=>True,'default'=>0),
    'memType' => array('type'=>'SMALLINT','primary_key'=>True,'default'=>''),
    'total' => array('type'=>'MONEY','default'=>0.00),
    'quantity' => array('type'=>'DOUBLE','default'=>0.00),
    'transCount' => array('type'=>'INT','default'=>0)
    );

    public function refresh_data($trans_db, $month, $year, $day=False){
        $start_id = date('Ymd',mktime(0,0,0,$month,1,$year));
        $start_date = date('Y-m-d',mktime(0,0,0,$month,1,$year));
        $end_id = date('Ymt',mktime(0,0,0,$month,1,$year));
        $end_date = date('Y-m-t',mktime(0,0,0,$month,1,$year));
        if ($day !== False){
            $start_id = date('Ymd',mktime(0,0,0,$month,$day,$year));
            $start_date = date('Y-m-d',mktime(0,0,0,$month,$day,$year));
            $end_id = $start_id;
            $end_date = $start_date;
        }

        $target_table = DTransactionsModel::selectDlog($start_date, $end_date);

        /* clear old entries */
        $sql = 'DELETE FROM '.$this->name.' WHERE date_id BETWEEN ? AND ?';
        $prep = $this->connection->prepare_statement($sql);
        $result = $this->connection->exec_statement($prep, array($start_id, $end_id));

        /* reload table from transarction archives 
           The process for this controller is iterative because of
           an old bug that assigns incorrect values to the transaction's
           memType column on records with trans_status 'M'. Using
           aggregates directly on the table over-counts memType zero
           so instead we count transactions one at a time.
        */
        $sql = "SELECT DATE_FORMAT(tdate, '%Y%m%d') as date_id,
            MAX(memType) as memType,
            CONVERT(SUM(total),DECIMAL(10,2)) as total,
            CONVERT(SUM(CASE WHEN trans_status='M' THEN itemQtty 
                WHEN unitPrice=0.01 THEN 1 ELSE quantity END),DECIMAL(10,2)) as quantity
            FROM $target_table WHERE
            tdate BETWEEN ? AND ? AND
            trans_type IN ('I','D') AND upc <> 'RRR'
            AND card_no <> 0 AND memType IS NOT NULL
            GROUP BY DATE_FORMAT(tdate,'%Y%m%d'), trans_num
            ORDER BY DATE_FORMAT(tdate,'%Y%m%d'), MAX(memType)";
        $prep = $this->connection->prepare_statement($sql);
        $result = $this->connection->exec_statement($prep, array($start_date.' 00:00:00',$end_date.' 23:59:59'));
        $this->reset();
        while($row = $this->connection->fetch_row($result)){
            if($this->date_id() != $row['date_id'] || $this->memType() != $row['memType']){
                if ($this->date_id() !== 0){ 
                    $this->save();
                }
                $this->reset();
                $this->date_id($row['date_id']);
                $this->memType($row['memType']);
                $this->total(0.00);
                $this->quantity(0.00);
                $this->transCount(0);
            }
            $this->total( $this->total() + $row['total'] );
            $this->quantity( $this->quantity() + $row['quantity'] );
            $this->transCount( $this->transCount() + 1 );
        }
        if ($this->date_id() !== ''){
            $this->save();
        }
    }
}

