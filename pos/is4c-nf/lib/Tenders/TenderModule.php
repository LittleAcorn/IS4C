<?php
/*******************************************************************************

    Copyright 2012 Whole Foods Co-op

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

/**
  @class TenderModule
  Base class for modular tenders
*/
class TenderModule 
{

    protected $tender_code;
    protected $amount;

    protected $name_string;
    protected $change_type;
    protected $change_string;
    protected $min_limit;
    protected $max_limit;

    /**
      Constructor
      @param $code two letter tender code
      @param $amt tender amount

      If you override this, be sure to call the
      parent constructor
    */
    public function TenderModule($code, $amt)
    {
        $this->tender_code = $code;
        $this->amount = $amt;

        $db = Database::pDataConnect();
        $query = "select TenderID,TenderCode,TenderName,TenderType,
            ChangeMessage,MinAmount,MaxAmount,MaxRefund from 
            tenders where tendercode = '".$this->tender_code."'";
        $result = $db->query($query);

        if ($db->num_rows($result) > 0) {
            $row = $db->fetch_array($result);
            $this->name_string = $row['TenderName'];
            $this->change_type = $row['TenderType'];
            $this->change_string = $row['ChangeMessage'];
            $this->min_limit = $row['MinAmount'];
            $this->max_limit = $row['MaxAmount'];
        } else {
            $this->name_string = '';
            $this->change_string = 'Change';
            $this->min_limit = 0;
            $this->max_limit = 0;
            $this->change_type = 'CA';
        }
    }

    /**
      Check for errors
      @return True or an error message string
    */
    public function errorCheck()
    {
        //force negative entered value when the total is negative.
        if (CoreLocal::get("amtdue") <0 && $this->amount >= 0) {
            $this->amount = -1 * $this->amount;
        }

        $clearButton = array('OK [clear]' => 'parseWrapper(\'CL\');');

        if (CoreLocal::get("LastID") == 0) {
            return DisplayLib::boxMsg(
                _("no transaction in progress"),
                '',
                false,
                $clearButton
            );
        } else if ($this->amount > 99999.99) {
            return DisplayLib::boxMsg(
                _("tender amount of") . " " . $this->amount . "<br />" . _("exceeds allowable limit"),
                '',
                false,
                $clearButton
            );
        } else if (CoreLocal::get("ttlflag") == 0) {
            return DisplayLib::boxMsg(
                _("transaction must be totaled before tender can be accepted"),
                '',
                false,
                array('Total [subtotal]' => 'parseWrapper(\'TL\');$(\'#reginput\').focus();', 'Dimiss [clear]' => 'parseWrapper(\'CL\');')
            );
        } else if ($this->name_string === "") {
            return DisplayLib::inputUnknown();
        } elseif ((($this->amount < (CoreLocal::get("amtdue") - 0.005)) || ($this->amount > (CoreLocal::get("amtdue") + 0.005)))
                     && CoreLocal::get("amtdue") < 0 
                     && $this->amount !=0){
            // the return tender needs to be exact because the transaction state can get weird.
            return DisplayLib::xboxMsg(
                _("return tender must be exact"),
                $clearButton
            );
        } elseif(CoreLocal::get("amtdue")>0 && $this->amount < 0) { 
            return DisplayLib::xboxMsg(
                _("Why are you using a negative number for a positve sale?"),
                $clearButton
            );
        }

        return true;
    }
    
    /**
      Set up state and redirect if needed
      @return True or a URL to redirect
    */
    public function preReqCheck()
    {
        if ($this->amount > $this->max_limit && CoreLocal::get("msgrepeat") == 0) {
            CoreLocal::set("boxMsg",
                "$" . $this->amount . " " . _("is greater than tender limit for") . " " . $this->name_string
            );
            CoreLocal::set('lastRepeat', 'confirmTenderAmount');
            CoreLocal::set('boxMsgButtons', array(
                'Confirm [enter]' => '$(\'#reginput\').val(\'\');submitWrapper();',
                'Cancel [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
            ));

            return MiscLib::base_url().'gui-modules/boxMsg2.php';
        } else if (CoreLocal::get('msgrepeat') == 1 && CoreLocal::get('lastRepeat') == 'confirmTenderAmount') {
            CoreLocal::set('msgrepeat', 0);
            CoreLocal::set('lastRepeat', '');
        }

        if ($this->amount - CoreLocal::get("amtdue") > 0) {
            CoreLocal::set("change",$this->amount - CoreLocal::get("amtdue"));
            CoreLocal::set("ChangeType", $this->change_type);
        } else {
            CoreLocal::set("change",0);
        }

        return true;
    }

    /**
      Add tender to the transaction
    */
    public function add()
    {
        TransRecord::addRecord(array(
            'description' => $this->name_string,
            'trans_type' => 'T',
            'trans_subtype' => $this->tender_code,
            'total' => -1 * $this->amount,
        ));
    }

    /**
      What type should be used for change records associated with this tender.
      @return string tender code
    */
    public function changeType()
    {
        return $this->change_type;
    }

    /**
      What description should be used for change records associated with this tender
      @return string change description
    */
    public function changeMsg()
    {
        return $this->change_string;
    }

    /**
      Allow the tender to be used without specifying a total
      @return boolean
    */
    public function allowDefault()
    {
        return true;
    }

    /**
      Value to use if no total is provided
      @return number
    */
    public function defaultTotal()
    {
        return CoreLocal::get('amtdue');
    }

    /**
      Prompt for the cashier when no total is provided
      @return string URL
    
      Typically this sets up session variables and returns
      the URL for boxMsg2.php.
    */
    public function defaultPrompt()
    {
        $amt = $this->DefaultTotal();
        CoreLocal::set('boxMsg',
            '<br />'
          . 'tender $' . sprintf('%.2f',$amt) . ' as ' . $this->name_string 
        );
        CoreLocal::set('strEntered', (100*$amt).$this->tender_code);
        CoreLocal::set('boxMsgButtons', array(
            'Confirm [enter]' => '$(\'#reginput\').val(\'\');submitWrapper();',
            'Cancel [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
        ));

        return MiscLib::base_url().'gui-modules/boxMsg2.php?quiet=1';
    }

    /**
      Error message shown if tender cannot be used without
      specifying a total
      @return html string
    */
    public function disabledPrompt()
    {
        return DisplayLib::boxMsg('Amount required for '.$this->name_string);
    }

}

