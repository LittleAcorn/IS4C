<?php

class VolunteerHoursParser extends Parser
{
    public function check($str)
    {
        if ($str == 'VHV') {
            return true;
        } elseif ($str == 'VHR') {
            return true;
        } else {
            return false;
        }
    }

    public function parse($str)
    {
        $ret = $this->default_json();
        list($hours, $dollars) = $this->getHours(CoreLocal::get('memberID'));
        if ($str == 'VHV') {
            $ret['output'] = DisplyLib::boxMsg(
                sprintf('Hours Available: %.2f<br />Value: $%.2f', $hours, $dollars),
                'Volunteer Hours',
                true
            );
        } elseif ($hours <= 0 || $dollars <= 0) {
            $ret['output'] = DisplyLib::boxMsg(
                _('No hours available'),
                'Volunteer Hours',
                true
            );
        } else {
            TransRecord::addRecord(array(
                'description' => 'VOLUNTEER HOURS',
                'trans_type' => 'T',
                'trans_subtype' => 'VH',
                'total' => MiscLib::truncate2(-1 * $dollars),
                'quantity' => $hours,
            ));
            $ret['output'] = DisplayLib::lastpage();
        }

        return $ret;
    }

    private function getHours($member)
    {
        $dbc = Database::mDataConnect();
        $dbc->selectDB(CoreLocal::get('ServerVolunteerDB'));
        $prep = $dbc->prepare('
            SELECT SUM(hoursWorked) - SUM(hoursRedeemed)
            FROM VolunteerHoursActivity
            WHERE cardNo=?');
        $balance = $dbc->getValue($prep, array($member));
        if ($balance === false) {
            return array(0, 0);
        }
        return array($balance, $balance*CoreLocal::get('VolunteerHourValue'));  
    }
}

