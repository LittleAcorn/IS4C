<?php

class VhRedeemedTask extends FannieTask
{
    public function run()
    {
        $dbc = FannieDB::get($this->config->get('TRANS_DB'));
        $settings = $this->config->get('PLUGIN_SETTINGS');
        $vhdb = $settings['VolunteerHoursDB'];

        $res = $dbc->query('
            SELECT card_no,
                tdate,
                trans_num,
                total,
                quantity
            FROM dlog_15
            WHERE trans_type=\'T\'
                AND trans_subtype=\'VH\'
        ');
        $chkP = $dbc->prepare('
            SELECT *
            FROM ' . $vhdb . $dbc->sep() . 'VolunteerHoursActivity
            WHERE cardNo=?
                AND tdate=?
                AND transNum=?');
        $addP = $dbc->prepare('
            INSERT INTO ' . $vhdb . $dbc->sep() . 'VolunteerHoursActivity
                (tdate, cardNo, hoursWorked, hoursRedeemed, transNum)
            VALUES
                (?, ?, 0, ?, ?)');
        while ($row = $dbc->fetchRow($res)) {
            $chkR = $dbc->execute($chkP, array($row['card_no'], $row['tdate'], $row['trans_num']));
            if ($dbc->numRows($chkR) == 0) {
                $dbc->execute($addP, array($row['tdate'], $row['card_no'], $row['quantity'], $row['trans_num']));
            }
        }
    }
}

