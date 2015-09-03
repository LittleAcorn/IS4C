<?php
/*******************************************************************************

    Copyright 2015 Whole Foods Co-op

    This file is part of CORE-POS.

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
  @class TimesheetDepartmentsModel
*/
class TimesheetDepartmentsModel extends BasicModel
{

    protected $name = "TimesheetDepartments";
    protected $preferred_db = 'plugin:TimesheetDatabase';

    protected $columns = array(
    'timesheetDepartmentID' => array('type'=>'INT', 'primary_key'=>true, 'increment'=>true),
    'name' => array('type'=>'VARCHAR(50)'),
    'number' => array('type'=>'INT'),
    );

    /* START ACCESSOR FUNCTIONS */

    public function timesheetDepartmentID()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["timesheetDepartmentID"])) {
                return $this->instance["timesheetDepartmentID"];
            } else if (isset($this->columns["timesheetDepartmentID"]["default"])) {
                return $this->columns["timesheetDepartmentID"]["default"];
            } else {
                return null;
            }
        } else if (func_num_args() > 1) {
            $value = func_get_arg(0);
            $op = $this->validateOp(func_get_arg(1));
            if ($op === false) {
                throw new Exception('Invalid operator: ' . func_get_arg(1));
            }
            $filter = array(
                'left' => 'timesheetDepartmentID',
                'right' => $value,
                'op' => $op,
                'rightIsLiteral' => false,
            );
            if (func_num_args() > 2 && func_get_arg(2) === true) {
                $filter['rightIsLiteral'] = true;
            }
            $this->filters[] = $filter;
        } else {
            if (!isset($this->instance["timesheetDepartmentID"]) || $this->instance["timesheetDepartmentID"] != func_get_args(0)) {
                if (!isset($this->columns["timesheetDepartmentID"]["ignore_updates"]) || $this->columns["timesheetDepartmentID"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["timesheetDepartmentID"] = func_get_arg(0);
        }
        return $this;
    }

    public function name()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["name"])) {
                return $this->instance["name"];
            } else if (isset($this->columns["name"]["default"])) {
                return $this->columns["name"]["default"];
            } else {
                return null;
            }
        } else if (func_num_args() > 1) {
            $value = func_get_arg(0);
            $op = $this->validateOp(func_get_arg(1));
            if ($op === false) {
                throw new Exception('Invalid operator: ' . func_get_arg(1));
            }
            $filter = array(
                'left' => 'name',
                'right' => $value,
                'op' => $op,
                'rightIsLiteral' => false,
            );
            if (func_num_args() > 2 && func_get_arg(2) === true) {
                $filter['rightIsLiteral'] = true;
            }
            $this->filters[] = $filter;
        } else {
            if (!isset($this->instance["name"]) || $this->instance["name"] != func_get_args(0)) {
                if (!isset($this->columns["name"]["ignore_updates"]) || $this->columns["name"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["name"] = func_get_arg(0);
        }
        return $this;
    }

    public function number()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["number"])) {
                return $this->instance["number"];
            } else if (isset($this->columns["number"]["default"])) {
                return $this->columns["number"]["default"];
            } else {
                return null;
            }
        } else if (func_num_args() > 1) {
            $value = func_get_arg(0);
            $op = $this->validateOp(func_get_arg(1));
            if ($op === false) {
                throw new Exception('Invalid operator: ' . func_get_arg(1));
            }
            $filter = array(
                'left' => 'number',
                'right' => $value,
                'op' => $op,
                'rightIsLiteral' => false,
            );
            if (func_num_args() > 2 && func_get_arg(2) === true) {
                $filter['rightIsLiteral'] = true;
            }
            $this->filters[] = $filter;
        } else {
            if (!isset($this->instance["number"]) || $this->instance["number"] != func_get_args(0)) {
                if (!isset($this->columns["number"]["ignore_updates"]) || $this->columns["number"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["number"] = func_get_arg(0);
        }
        return $this;
    }
    /* END ACCESSOR FUNCTIONS */
}

