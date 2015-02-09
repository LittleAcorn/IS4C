<?php
/*******************************************************************************

   Copyright 2010 Whole Foods Co-op

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

/* --COMMENTS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

	* 24Oct2013 Eric Lee Defeated:
	*                    + A WEFC_Toronto-only textbox for collecting Member Card#
	*  5Oct2012 Eric Lee Added:
	*                    + A WEFC_Toronto-only chunk for collecting Member Card#
	*                    + A general facility for displaying an error encountered in preprocess()
	*                       in body_content() using temp_message.

*/

include_once(dirname(__FILE__).'/../lib/AutoLoader.php');

class memlist extends NoInputPage 
{

	private $temp_result;
	private $temp_num_rows;
	private $entered;
	private $db;
	private $temp_message;

	private $results = array();
	private $submitted = false;

	function preprocess()
    {
		// set variable ahead of time
		// so we know if lookup found no one
		// vs. lookup didn't happen
		$this->submitted = false;

		$entered = "";
		if (isset($_REQUEST['idSearch']) && strlen($_REQUEST['idSearch']) > 0) {
			$entered = $_REQUEST['idSearch'];
		} else if (isset($_REQUEST['search'])) {
			$entered = strtoupper(trim($_REQUEST["search"]));
			$entered = str_replace("'", "''", $entered);
		} else {
            return true;
        }

		if (substr($entered, -2) == "ID") {
            $entered = substr($entered, 0, strlen($entered) - 2);
        }

		$personNum = false;
		$memberID = false;
		if (strstr($entered,"::") !== false) {
			// Values of memlist items are "CardNo::personNum"
			list($memberID,$personNum) = explode("::",$entered);
			$this->submitted = true;
		}

		// No input available, stop
		if (!$entered || strlen($entered) < 1 || $entered == "CL") {
			$this->change_page($this->page_url."gui-modules/pos2.php");
			return false;
		}
		else if ($memberID === false && $personNum === false){
			// find the member
			$lookups = AutoLoader::ListModules('MemberLookup', True);
			foreach ($lookups as $class) {
				if (!class_exists($class)) continue;
				$obj = new $class();

				if (is_numeric($entered) && !$obj->handle_numbers()) {
					continue;
				} else if (!is_numeric($entered) && !$obj->handle_text()) {
					continue;
				} else if (is_numeric($entered)) {
					$chk = $obj->lookup_by_number($entered);
					if ($chk['url'] !== false) {
						$this->change_page($chk['url']);

						return false;		
					}
					foreach($chk['results'] as $key=>$val) {
						$this->results[$key] = $val;
                    }
				} else if(!is_numeric($entered)) {
					$chk = $obj->lookup_by_text($entered);
					if ($chk['url'] !== false) {
						$this->change_page($chk['url']);

						return false;		
					}
					foreach ($chk['results'] as $key=>$val) {
						$this->results[$key] = $val;
                    }
				}
			}
			$this->submitted = true;
		}

		// if theres only 1 match don't show the memlist
		// when it's the default non-member account OR
		// when name verification is disabled
		if (count($this->results) == 1 && 
		    (CoreLocal::get("verifyName")==0 || $entered == CoreLocal::get('defaultNonMem'))) {
			$key = array_pop(array_keys($this->results));
			list($memberID, $personNum) = explode('::',$key);
		}

		// we have exactly one row and 
		// don't need to confirm any further
		if ($memberID !== false && $personNum !== false){
			if ($memberID == CoreLocal::get('defaultNonMem')) {
				$personNum = 1;
            }
			$db_a = Database::pDataConnect();
			$query = $db_a->prepare_statement('SELECT CardNo, personNum,
				LastName, FirstName,CashBack,Balance,Discount,
				ChargeOk,WriteChecks,StoreCoupons,Type,
				memType,staff,SSI,Purchases,NumberOfChecks,memCoupons,
				blueLine,Shown,id FROM custdata WHERE CardNo=?
				AND personNum=?');
			$result = $db_a->exec_statement($query,array($memberID, $personNum));
			$row = $db_a->fetch_row($result);
			PrehLib::setMember($row["CardNo"], $personNum, $row);

			// WEFC_Toronto: If a Member Card # was entered when the choice from the list was made,
			// add the memberCards record.
			if (CoreLocal::get('store') == "WEFC_Toronto") {
				$mmsg = "";
				if (isset($_REQUEST['memberCard']) && $_REQUEST['memberCard'] != "") {
					$memberCard = $_REQUEST['memberCard'];
					if (!is_numeric($memberCard) || strlen($memberCard) > 5 || $memberCard == 0) {
						$mmsg = "Bad Member Card# format >{$memberCard}<";
					} else {
						$upc = sprintf("00401229%05d", $memberCard);
						// Check that it isn't already there, perhaps for someone else.
						$mQ = "SELECT card_no FROM memberCards where card_no = {$row['CardNo']}";
						$mResult = $db_a->query($mQ);
						$mNumRows = $db_a->num_rows($mResult);
						if ($mNumRows > 0) {
							$mmsg = "{$row['CardNo']} is already associated with another Member Card";
						} else {
							$mQ = "INSERT INTO memberCards (card_no, upc) VALUES ({$row['CardNo']}, '$upc')";
							$mResult = $db_a->query($mQ);
							if ( !$mResult ) {
								$mmsg = "Linking membership to Member Card failed.";
							}
						}
					}
				}
				if ($mmsg != "") {
					// Prepare to display the error.
					$this->temp_message = $mmsg;

					return true;
				}
			// /WEFC_Toronto bit.
			}

			// don't bother with unpaid balance check if there is no balance
			if ($entered != CoreLocal::get("defaultNonMem") && CoreLocal::get('balance') > 0) {
				$unpaid = PrehLib::check_unpaid_ar($row["CardNo"]);
				if ($unpaid) {
					$this->change_page($this->page_url."gui-modules/UnpaidAR.php");
				} else {
					$this->change_page($this->page_url."gui-modules/pos2.php");
                }
			} else {
				$this->change_page($this->page_url."gui-modules/pos2.php");
            }

			return false;
		}

		// Prepare to display the memlist (list to choose from).
		$this->temp_message = "";

		return true;

	} // END preprocess() FUNCTION

	function head_content()
    {
		if (count($this->results) > 0) {
			$this->add_onload_command("selectSubmit('#search', '#selectform')\n");
			$this->add_onload_command("\$('#search').focus();\n");
		} else {
			$this->default_parsewrapper_js('reginput','selectform');
			$this->add_onload_command("\$('#reginput').focus();\n");
		}
		?>
        <script type="text/javascript" src="../js/selectSubmit.js"></script>
		<?php
	} // END head() FUNCTION

	function body_content()
    {
		$message = $this->temp_message;

		echo "<div class=\"baseHeight\">"
			."<form id=\"selectform\" method=\"post\" action=\"{$_SERVER['PHP_SELF']}\">";

		// First check for a problem found in preprocess.
		if ($message != "") {
			echo "
			<div class=\"colored centeredDisplay\">
				<span class=\"larger\">
                    {$message} <br />" .
                    _("enter member number or name") . "
			    </span>
                <br />
				<input type=\"text\" name=\"search\" size=\"15\"
			       	onblur=\"\$('#reginput').focus();\" id=\"reginput\" />
				<br />press [enter] to cancel
			</div>";
		} else if (count($this->results) < 1) {
            /* for no results, just throw up a re-do
             * otherwise, put results in a select box
             */
			echo "
			<div class=\"colored centeredDisplay rounded\">
				<span class=\"larger\">";
			if (!$this->submitted) {
				echo _("member search")."<br />"._("enter member number or name");
			} else {
				echo _("no match found")."<br />"._("next search or member number");
            }
			echo "</span>
                <p>
				<input type=\"text\" name=\"search\" size=\"15\"
			       	onblur=\"\$('#reginput').focus();\" id=\"reginput\" />
				</p>
                <button class=\"pos-button\" type=\"button\"
                    onclick=\"\$('#reginput').val('');\$('#selectform').submit();\">
                    Cancel [enter]
                </button>
			</div>";
		} else {
			echo "<div class=\"listbox\">"
				."<select name=\"search\" size=\"15\" "
				."onblur=\"\$('#search').focus();\" ondblclick=\"document.forms['selectform'].submit();\" id=\"search\">";

			$selectFlag = 0;
			foreach ($this->results as $optval => $label) {
				echo '<option value="'.$optval.'"';
				if ($selectFlag == 0) {
					echo ' selected';
					$selectFlag = 1;
				}
				echo '>'.$label.'</option>';
            }
			echo "</select></div><!-- /.listbox -->";
            if (CoreLocal::get('touchscreen')) {
                echo '<div class="listbox listboxText">'
                . '<button type="button" class="pos-button coloredArea"
                    onclick="pageUp(\'#search\');">
                    <img src="../graphics/pageup.png" width="16" height="16" />
                   </button>'
                . '<br /><br />'
                . '<button type="button" class="pos-button coloredArea"
                    onclick="scrollUp(\'#search\');">
                    <img src="../graphics/up.png" width="16" height="16" />
                   </button>'
                . '<br /><br />'
                . '<button type="button" class="pos-button coloredArea"
                    onclick="scrollDown(\'#search\');">
                    <img src="../graphics/down.png" width="16" height="16" />
                   </button>'
                . '<br /><br />'
                . '<button type="button" class="pos-button coloredArea"
                    onclick="pageDown(\'#search\');">
                    <img src="../graphics/pagedown.png" width="16" height="16" />
                   </button>'
                . '</div>';
            }
			echo "<div class=\"listboxText coloredText centerOffset\">"
				. _("use arrow keys to navigate")
                . '<p><button type="submit" class="pos-button wide-button coloredArea">
                    OK <span class="smaller">[enter]</span>
                    </button></p>'
                . '<p><button type="submit" class="pos-button wide-button errorColoredArea"
                    onclick="$(\'#search option:selected\').val(\'\');">
                    Cancel <span class="smaller">[clear]</span>
                    </button></p>'
                ."</div><!-- /.listboxText coloredText .centerOffset -->"
				."<div class=\"clear\"></div>";
		}
		echo "</form></div>";
	} // END body_content() FUNCTION

// /class memlist
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
	new memlist();
}

?>
