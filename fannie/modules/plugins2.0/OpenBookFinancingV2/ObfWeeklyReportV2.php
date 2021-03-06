<?php
/*******************************************************************************

    Copyright 2014 Whole Foods Co-op

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

include(dirname(__FILE__).'/../../../config.php');
if (!class_exists('FannieAPI')) {
    include_once($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}

class ObfWeeklyReportV2 extends ObfWeeklyReport
{
    protected $sortable = false;
    protected $no_sort_but_style = true;

    protected $report_headers = array(
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Last Year', 'Plan Goal', '% Store', 'Trend', 'Actual', '% Growth', '% Store', 'Current O/U', 'Long-Term O/U'),
        array('', 'Current Year', 'Last Year', '', '', '', '', '', '', ''),
    );

    protected $class_lib = 'ObfLibV2';

    public function preprocess()
    {
        return FannieReportPage::preprocess();
    }

    public function fetch_report_data()
    {
        $class_lib = $this->class_lib;
        $dbc = $class_lib::getDB();
        
        $week = $class_lib::getWeek($dbc);
        $week->obfWeekID($this->form->weekID);
        $week->load();

        $colors = array(
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
            '#CDB49B',
            '#99C299',
        );
        
        $labor = new ObfLaborModelV2($dbc);
        $labor->obfWeekID($week->obfWeekID());
        
        /**
           Timestamps for the start and end of
           the current week
        */
        $start_ts = strtotime($week->startDate());
        $end_ts = mktime(0, 0, 0, date('n', $start_ts), date('j', $start_ts)+6, date('Y', $start_ts));

        list($year, $month) = $this->findYearMonth($start_ts, $end_ts);

        /**
          Use the entire month from the previous calendar year
          as the time period for year-over-year comparisons
        */
        $start_ly = mktime(0, 0, 0, $month, 1, $year-1);
        $end_ly = mktime(0, 0, 0, $month, date('t', $start_ly), $year-1);

        $future = $end_ts >= strtotime(date('Y-m-d')) ? true: false;

        /**
          Sales information is cached to avoid expensive
          aggregate queries
        */
        $sales = $class_lib::getCache($dbc);
        $sales->obfWeekID($week->obfWeekID());
        $sales->actualSales(0, '>');
        $num_cached = $sales->find();
        if (count($num_cached) == 0 || true) {
            $dateInfo = array(
                'start_ts' => $start_ts,
                'end_ts' => $end_ts,
                'start_ly' => $start_ly,
                'end_ly' => $end_ly,
            );
            $this->updateSalesCache($week, $num_cached, $dateInfo);
        }

        // record set to return
        $data = array();                

        /**
          Information about sales
          - thisYear => sales for the current week
          - lastYear => sales for the same week last year
          - projected => planned sales for the current week
                         based on sales growth goals
          - trend => expected sales for the current week based
                     on recent history sales trends
          - quarterActual => actual sales for the quarter
          - quarterProjected => planned sales for the quarter based
                                on sales growth goals
          - quarterLaborSales => actual sales for the quarter as
                                 defined by labor measurements

          "Quarter" is not necessarily a calendar quarter. It's
          whatever period is currently defined for the "long-term"
          over under column. This period can be defined separately
          for sales and labor. A separate sales number is always
          tracked in concert with the long-term labor period so that
          the long-term sales per labor hour number makes sense.
        */
        $total_sales = new stdClass();
        $total_sales->thisYear = 0.0;
        $total_sales->lastYear = 0.0;
        $total_sales->projected = 0.0;
        $total_sales->trend = 0.0;
        $total_sales->quarterActual = 0.0;
        $total_sales->quarterProjected = 0.0;
        $total_sales->quarterLaborSales = 0.0;

        /**
          Information about number of transactions
          - thisYear => transactions for the current week
          - lastYear => transactions for the same week last year
          - quarterThisYear => transactions for the quarter
          - quarterLastYear => transactions for the same quarter
                               year-over-year
          
          See sales above for more info about "Quarters"
        */
        $total_trans = new stdClass();
        $total_trans->thisYear = 0;
        $total_trans->lastYear = 0;
        $total_trans->quarterThisYear = 0;
        $total_trans->quarterLastYear = 0;

        /**
          Information about labor hours
          - actual => actual hours for the current week
          - projected => planned hours for the current week
                         based on sales growth and SPLH goals
          - trend => expected hours for the current week based
                     on recent history sales trends and
                     SPH goals
          - quarterActual => actual hours for the quarter
          - quarterProjected => planned hours for the quarter
                                based on sales growth goals
          
          See sales above for more info about "Quarters"
        */
        $total_hours = new stdClass();
        $total_hours->actual = 0.0;
        $total_hours->projected = 0.0;
        $total_hours->trend = 0.0;
        $total_hours->quarterActual = 0.0;
        $total_hours->quarterProjected = 0.0;

        $qtd_sales_ou = 0;
        $qtd_hours_ou = 0;

        /**
          Look up sales for the week in a given category
        */
        $salesP = $dbc->prepare('SELECT s.actualSales,
                                    s.lastYearSales,
                                    s.growthTarget,
                                    n.super_name,
                                    s.superID,
                                    s.transactions,
                                    s.lastYearTransactions
                                 FROM ObfSalesCache AS s
                                    LEFT JOIN ' . $this->config->get('OP_DB') . $dbc->sep() . 'superDeptNames
                                        AS n ON s.superID=n.superID
                                 WHERE s.obfWeekID=?
                                    AND s.obfCategoryID=?
                                 ORDER BY s.superID,n.super_name');

        /**
          Look up sales for the [sales] quarter in a given category
        */
        $quarterSalesP = $dbc->prepare('SELECT SUM(s.actualSales) AS actual,
                                            SUM(s.lastYearSales) AS lastYear,
                                            SUM(s.lastYearSales * (1+s.growthTarget)) AS plan,
                                            SUM(s.transactions) AS trans,
                                            SUM(s.lastYearTransactions) AS ly_trans
                                        FROM ObfSalesCache AS s
                                            INNER JOIN ObfWeeks AS w ON s.obfWeekID=w.obfWeekID
                                        WHERE w.obfQuarterID = ?
                                            AND s.obfCategoryID = ?
                                            AND s.superID=?
                                            AND w.endDate <= ?'); 

        /**
          Look up labor for the [labor] quarter in a given category
        */
        $quarterLaborP = $dbc->prepare('SELECT SUM(l.hours) AS hours,
                                            SUM(l.wages) AS wages,
                                            AVG(l.laborTarget) as laborTarget,
                                            AVG(l.averageWage) as averageWage,
                                            SUM(l.hoursTarget) as hoursTarget
                                        FROM ObfLabor AS l
                                            INNER JOIN ObfWeeks AS w ON l.obfWeekID=w.obfWeekID
                                        WHERE w.obfLaborQuarterID=?
                                            AND l.obfCategoryID=?
                                            AND w.endDate <= ?');

        /**
          Look up sales for the [labor] quarter in a given category

          Since the "quarter" can differ for long-term sales and
          long-term labor, this value is needed to calculate
          long-term SPLH correctly.
        */
        $quarterSplhP = $dbc->prepare('SELECT SUM(c.actualSales) AS actualSales,
                                            SUM(c.lastYearSales * (1+c.growthTarget)) AS planSales
                                        FROM ObfLabor AS l
                                            INNER JOIN ObfWeeks AS w ON l.obfWeekID=w.obfWeekID
                                            INNER JOIN ObfSalesCache AS c ON c.obfWeekID=l.obfWeekID
                                                AND c.obfCategoryID=l.obfCategoryID
                                        WHERE w.obfLaborQuarterID=?
                                            AND l.obfCategoryID=?
                                            AND w.endDate <= ?');
        /**
          Trends are based on the previous
          thirteen weeks that contain sales data. 
          First build a list of week IDs, then
          prepare statement to query a specific category
          of sales data.
        */
        $splhWeeks = '(';
        $splhWeekQ = '
            SELECT c.obfWeekID
            FROM ObfSalesCache AS c
                INNER JOIN ObfWeeks AS w ON c.obfWeekID=w.obfWeekID
            WHERE c.obfWeekID < ?
            GROUP BY c.obfWeekID
            HAVING SUM(c.actualSales) > 0
            ORDER BY MAX(w.endDate) DESC';
        $splhWeekQ = $dbc->add_select_limit($splhWeekQ, 13);
        $splhWeekP = $dbc->prepare($splhWeekQ);
        $splhWeekR = $dbc->execute($splhWeekP, array($week->obfWeekID()));
        while ($splhWeekW = $dbc->fetch_row($splhWeekR)) {
            $splhWeeks .= sprintf('%d,', $splhWeekW['obfWeekID']);
        }
        if ($splhWeeks == '(') {
            $splhWeeks .= '-99999';
        }
        $splhWeeks = substr($splhWeeks, 0, strlen($splhWeeks)-1) . ')';
        $trendQ = '
            SELECT 
                actualSales,
                lastYearSales
            FROM ObfSalesCache AS c
            WHERE c.obfCategoryID = ?
                AND c.superID = ?
                AND c.actualSales > 0
                AND c.obfWeekID IN ' . $splhWeeks . '
            ORDER BY c.obfWeekID';
        $trendP = $dbc->prepare($trendQ);

        /**
          LOOP ONE
          Examine OBF Categories that have sales. These will include
          both sales and labor information
        */
        $categories = new ObfCategoriesModelV2($dbc);
        $categories->hasSales(1);
        foreach ($categories->find('name') as $category) {
            $data[] = array($category->name(), '', '', '', '', '', '', '', '', '',
                        'meta' => FannieReportPage::META_BOLD | FannieReportPage::META_COLOR,
                        'meta_background' => $colors[0],
                        'meta_foreground' => 'black',
            );
            $sum = array(0.0, 0.0);
            $dept_proj = 0.0;
            $dept_trend = 0;
            $salesR = $dbc->execute($salesP, array($week->obfWeekID(), $category->obfCategoryID()));
            $qtd_dept_plan = 0;
            $qtd_dept_sales = 0;
            $qtd_dept_ou = 0;
            /**
              Go through sales records for the category
            */
            while ($row = $dbc->fetch_row($salesR)) {
                $proj = ($row['lastYearSales'] * $row['growthTarget']) + $row['lastYearSales'];

                $trendR = $dbc->execute($trendP, array($category->obfCategoryID(), $row['superID']));
                $trend_data = array();
                $t_count = 0;
                while ($trendW = $dbc->fetchRow($trendR)) {
                    $trend_data[] = array($t_count, $trendW['actualSales']);
                    $t_count++;
                }
                $trend_data = \COREPOS\Fannie\API\lib\Stats::removeOutliers($trend_data);
                $exp = \COREPOS\Fannie\API\lib\Stats::exponentialFit($trend_data);
                $trend1 = exp($exp->a) * exp($exp->b * $t_count);

                $dept_trend += $trend1;
                $total_sales->trend += $trend1;

                $quarter = $dbc->execute($quarterSalesP, 
                    array($week->obfQuarterID(), $category->obfCategoryID(), $row['superID'], date('Y-m-d 00:00:00', $end_ts))
                );
                if ($dbc->num_rows($quarter) == 0) {
                    $quarter = array('actual'=>0, 'lastYear'=>0, 'plan'=>0, 'trans'=>0, 'ly_trans'=>0);
                } else {
                    $quarter = $dbc->fetch_row($quarter);
                }
                $qtd_dept_plan += $quarter['plan'];
                $qtd_dept_sales += $quarter['actual'];
                $total_trans->quarterThisYear = $quarter['trans'];
                $total_trans->quarterLastYear = $quarter['ly_trans'];

                $record = array(
                    $row['super_name'],
                    number_format($row['lastYearSales'], 0),
                    number_format($proj, 0),
                    number_format($proj, 0), // converts to % of sales
                    number_format($trend1, 0),
                    number_format($row['actualSales'], 0),
                    sprintf('%.2f%%', $this->percentGrowth($row['actualSales'], $row['lastYearSales'])),
                    number_format($row['actualSales'], 0), // converts to % of sales
                    number_format($row['actualSales'] - $proj, 0),
                    number_format($quarter['actual'] - $quarter['plan'], 0),
                    'meta' => FannieReportPage::META_COLOR,
                    'meta_background' => $colors[0],
                    'meta_foreground' => 'black',
                );
                $sum[0] += $row['actualSales'];
                $sum[1] += $row['lastYearSales'];
                $total_sales->thisYear += $row['actualSales'];
                $total_sales->lastYear += $row['lastYearSales'];
                if ($total_trans->thisYear == 0) {
                    $total_trans->thisYear = $row['transactions'];
                }
                if ($total_trans->lastYear == 0) {
                    $total_trans->lastYear = $row['lastYearTransactions'];
                }
                $total_sales->projected += $proj;
                $dept_proj += $proj;
                $total_sales->quarterProjected += $quarter['plan'];
                $total_sales->quarterActual += $quarter['actual'];
                $qtd_sales_ou += ($quarter['actual'] - $quarter['plan']);
                $qtd_dept_ou += ($quarter['actual'] - $quarter['plan']);
                $data[] = $record;
            }

            /** total sales for the category **/
            $record = array(
                'Total',
                number_format($sum[1], 0),
                number_format($dept_proj, 0),
                number_format($dept_proj, 0), // % of store sales re-written later
                number_format($dept_trend, 0),
                number_format($sum[0], 0),
                sprintf('%.2f%%', $this->percentGrowth($sum[0], $sum[1])),
                number_format($sum[0], 0),
                number_format($sum[0] - $dept_proj, 0),
                number_format($qtd_dept_ou, 0),
                'meta' => FannieReportPage::META_COLOR | FannieReportPage::META_BOLD,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );
            $data[] = $record;

            /**
              Now labor values based on sales calculationsabove
            */
            $labor->obfCategoryID($category->obfCategoryID());
            $labor->load();
            // use SPLH instead of pre-allocated
            $proj_hours = $dept_proj / $category->salesPerLaborHourTarget();
            $trend_hours = $dept_trend / $category->salesPerLaborHourTarget();
            // approximate wage to convert hours into dollars
            $average_wage = 0;
            if ($labor->hours() != 0) {
                $average_wage = $labor->wages() / ((float)$labor->hours());
            }
            $proj_wages = $proj_hours * $average_wage;
            $trend_wages = $trend_hours * $average_wage;

            $quarter = $dbc->execute($quarterLaborP, 
                array($week->obfLaborQuarterID(), $labor->obfCategoryID(), date('Y-m-d 00:00:00', $end_ts))
            );
            if ($dbc->num_rows($quarter) == 0) {
                $quarter = array('hours'=>0, 'wages'=>0, 'laborTarget'=>0, 'hoursTarget'=>0, 'actualSales' => 0);
            } else {
                $quarter = $dbc->fetch_row($quarter);
            }
            $qt_splh = $dbc->execute($quarterSplhP,
                array($week->obfLaborQuarterID(), $labor->obfCategoryID(), date('Y-m-d 00:00:00', $end_ts))
            );
            if ($dbc->num_rows($qt_splh)) {
                $row = $dbc->fetch_row($qt_splh);
                $quarter['actualSales'] = $row['actualSales'];
                $quarter['planSales'] = $row['planSales'];
            }
            $qt_average_wage = $quarter['hours'] == 0 ? 0 : $quarter['wages'] / ((float)$quarter['hours']);
            $qt_proj_hours = $quarter['planSales'] / $category->salesPerLaborHourTarget();
            $qt_proj_labor = $qt_proj_hours * $qt_average_wage;
            $total_hours->quarterActual += $quarter['hours'];
            $total_hours->quarterProjected += $qt_proj_hours;
            $total_sales->quarterLaborSales += $quarter['actualSales'];

            $data[] = array(
                'Hours',
                '',
                number_format($proj_hours, 0),
                '',
                number_format($trend_hours, 0),
                number_format($labor->hours(), 0),
                sprintf('%.2f%%', $this->percentGrowth($labor->hours(), $proj_hours)),
                '',
                number_format($labor->hours() - $proj_hours, 0),
                number_format($quarter['hours'] - $qt_proj_hours, 0),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );
            $total_hours->actual += $labor->hours();
            $qtd_hours_ou += ($quarter['hours'] - $qt_proj_hours);

            $total_hours->projected += $proj_hours;
            $total_hours->trend += $trend_hours;

            $quarter_actual_sph = $quarter['hours'] == 0 ? 0 : ($qtd_dept_sales)/($quarter['hours']);
            $quarter_proj_sph = ($qt_proj_hours == 0) ? 0 : ($qtd_dept_plan)/($qt_proj_hours);
            $data[] = array(
                'Sales per Hour',
                '',
                number_format($dept_proj / $proj_hours, 2),
                '',
                number_format($dept_trend / $trend_hours, 2),
                number_format($labor->hours() == 0 ? 0 : $sum[0] / $labor->hours(), 2),
                sprintf('%.2f%%', $this->percentGrowth(($labor->hours() == 0 ? 0 : $sum[0]/$labor->hours()), $dept_proj/$proj_hours)),
                '',
                number_format(($labor->hours() == 0 ? 0 : $sum[0]/$labor->hours()) - ($dept_proj / $proj_hours), 2),
                number_format($quarter_actual_sph - $quarter_proj_sph, 2),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );

            $data[] = array('meta'=>FannieReportPage::META_REPEAT_HEADERS);

            if (count($colors) > 1) {
                array_shift($colors);
            }
        }

        /**
          Now that total sales for the all categories have been calculated,
          go back and divide specific columns by total sales to get
          percentage of sales
        */
        for ($i=0; $i<count($data); $i++) {
            if (isset($data[$i][7]) && preg_match('/^[\d,]+$/', $data[$i][7])) {
                $amt = str_replace(',', '', $data[$i][7]);
                $percentage = ($total_sales->thisYear == 0) ? 0.00 : ((float)$amt) / ((float)$total_sales->thisYear);
                $data[$i][7] = number_format($percentage*100, 2) . '%';
            }
            if (isset($data[$i][3]) && preg_match('/^[\d,]+$/', $data[$i][3])) {
                $amt = str_replace(',', '', $data[$i][3]);
                $percentage = ((float)$amt) / ((float)$total_sales->projected);
                $data[$i][3] = number_format($percentage*100, 2) . '%';
            }
        }

        /**
          LOOP TWO
          Examine OBF Categories without sales. These will include
          only labor information
        */
        $cat = new ObfCategoriesModelV2($dbc);
        $cat->hasSales(0);
        $cat->name('Admin', '<>');
        foreach ($cat->find('name') as $c) {
            $data[] = array($c->name(), '', '', '', '', '', '', '', '', '',
                        'meta' => FannieReportPage::META_BOLD | FannieReportPage::META_COLOR,
                        'meta_background' => $colors[0],
                        'meta_foreground' => 'black',
            );
            $labor->obfCategoryID($c->obfCategoryID());
            $labor->load();

            $quarter = $dbc->execute($quarterLaborP, 
                array($week->obfLaborQuarterID(), $labor->obfCategoryID(), date('Y-m-d 00:00:00', $end_ts))
            );
            if ($dbc->num_rows($quarter) == 0) {
                $quarter = array('hours'=>0, 'wages'=>0, 'laborTarget'=>0, 'hoursTarget'=>0);
            } else {
                $quarter = $dbc->fetch_row($quarter);
            }
            $qt_average_wage = $quarter['hours'] == 0 ? 0 : $quarter['wages'] / ((float)$quarter['hours']);
            $qt_proj_hours = $total_sales->quarterProjected / $c->salesPerLaborHourTarget();
            $qt_proj_labor = $qt_proj_hours * $qt_average_wage;
            $total_hours->quarterActual += $quarter['hours'];
            $total_hours->quarterProjected += $qt_proj_hours;

            $average_wage = 0;
            if ($labor->hours() != 0) {
                $average_wage = $labor->wages() / ((float)$labor->hours());
            }
            // use SPLH instead of pre-allocated
            $proj_hours = $total_sales->projected / $c->salesPerLaborHourTarget();
            $proj_wages = $proj_hours * $average_wage;

            $trend_hours = $total_sales->trend / $c->salesPerLaborHourTarget();
            $trend_wages = $trend_hours * $average_wage;

            $data[] = array(
                'Hours',
                '',
                number_format($proj_hours, 0),
                '',
                number_format($trend_hours, 0),
                number_format($labor->hours(), 0),
                '',
                '',
                number_format($labor->hours() - $proj_hours, 0),
                number_format($quarter['hours'] - $qt_proj_hours, 0),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );
            $qtd_hours_ou += ($quarter['hours'] - $qt_proj_hours);

            $quarter_actual_sph = $quarter['hours'] == 0 ? 0 : ($total_sales->quarterActual)/($quarter['hours']);
            $quarter_proj_sph = $qt_proj_hours == 0 ? 0 : ($total_sales->quarterProjected)/($qt_proj_hours);
            $data[] = array(
                'Sales per Hour',
                '',
                sprintf('%.2f', $total_sales->projected / $proj_hours),
                '',
                sprintf('%.2f', $total_sales->trend / $trend_hours),
                number_format($labor->hours() == 0 ? 0 : $total_sales->thisYear / $labor->hours(), 2),
                '',
                '',
                number_format(($labor->hours() == 0 ? 0 : $total_sales->thisYear/$labor->hours()) - ($total_sales->projected / $proj_hours), 2),
                number_format($quarter_actual_sph - $quarter_proj_sph, 2),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );

            $data[] = array('meta'=>FannieReportPage::META_REPEAT_HEADERS);

            $total_hours->actual += $labor->hours();
            $total_hours->projected += $proj_hours;
            $total_hours->trend += $trend_hours;

            if (count($colors) > 1) {
                array_shift($colors);
            }
        }

        /**
           Storewide totals section
        */
        $data[] = array('Total Store', '', '', '', '', '', '', '', '', '',
                        'meta' => FannieReportPage::META_BOLD | FannieReportPage::META_COLOR,
                        'meta_background' => $colors[0],
                        'meta_foreground' => 'black',
        );
        $data[] = array(
            'Sales',
            number_format($total_sales->lastYear, 0),
            number_format($total_sales->projected, 0),
            '',
            number_format($total_sales->trend, 0),
            number_format($total_sales->thisYear, 0),
            sprintf('%.2f%%', $this->percentGrowth($total_sales->thisYear, $total_sales->lastYear)),
            '',
            number_format($total_sales->thisYear - $total_sales->projected, 0),
            number_format($qtd_sales_ou, 0),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $data[] = array(
            'Hours',
            '',
            number_format($total_hours->projected, 0),
            '',
            number_format($total_hours->trend, 0),
            number_format($total_hours->actual, 0),
            '',
            '',
            number_format($total_hours->actual - $total_hours->projected, 0),
            number_format($qtd_hours_ou, 0),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $quarter_actual_sph = $total_hours->quarterActual == 0 ? 0 : ($total_sales->quarterActual)/($total_hours->quarterActual);
        $quarter_proj_sph = $total_hours->quarterProjected == 0 ? 0 : ($total_sales->quarterProjected)/($total_hours->quarterProjected);
        $data[] = array(
            'Sales per Hour',
            '',
            sprintf('%.2f', $total_sales->projected / $total_hours->projected),
            '',
            sprintf('%.2f', $total_sales->trend / $total_hours->trend),
            number_format($total_hours->actual == 0 ? 0 : $total_sales->thisYear / $total_hours->actual, 2),
            '',
            '',
            number_format(($total_hours->actual == 0 ? 0 : $total_sales->thisYear/$total_hours->actual) - ($total_sales->projected/$total_hours->projected), 2),
            number_format($quarter_actual_sph - $quarter_proj_sph, 2),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $proj_trans = $total_trans->lastYear * 1.05;
        $qtd_proj_trans = $total_trans->quarterLastYear * 1.05;
        $data[] = array(
            'Transactions',
            number_format($total_trans->lastYear),
            number_format($proj_trans),
            '',
            '',
            number_format($total_trans->thisYear),
            sprintf('%.2f%%', $this->percentGrowth($total_trans->thisYear, $total_trans->lastYear)),
            '',
            number_format($total_trans->thisYear - $proj_trans),
            number_format($total_trans->quarterThisYear - $qtd_proj_trans),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $data[] = array(
            'Average Basket',
            number_format($total_sales->lastYear / $total_trans->lastYear, 2),
            number_format($total_sales->projected / $proj_trans, 2),
            '',
            '',
            number_format($total_trans->thisYear == 0 ? 0 : $total_sales->thisYear / $total_trans->thisYear, 2),
            sprintf('%.2f%%', $this->percentGrowth($total_trans->thisYear == 0 ? 0 : $total_sales->thisYear/$total_trans->thisYear, $total_sales->lastYear/$total_trans->lastYear)),
            '',
            number_format(($total_trans->thisYear == 0 ? 0 : $total_sales->thisYear/$total_trans->thisYear) - ($total_sales->projected/$proj_trans), 2),
            number_format(($total_sales->quarterActual/$total_trans->quarterThisYear) - ($total_sales->quarterProjected/$qtd_proj_trans), 2),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        if (count($colors) > 1) {
            array_shift($colors);
        }

        $data[] = array('meta'=>FannieReportPage::META_REPEAT_HEADERS);
        $cat = new ObfCategoriesModelV2($dbc);
        $cat->hasSales(0);
        $cat->name('Admin');
        foreach ($cat->find('name') as $c) {
            $data[] = array($c->name(), '', '', '', '', '', '', '', '', '',
                        'meta' => FannieReportPage::META_BOLD | FannieReportPage::META_COLOR,
                        'meta_background' => $colors[0],
                        'meta_foreground' => 'black',
            );
            $labor->obfCategoryID($c->obfCategoryID());
            $labor->load();

            $quarter = $dbc->execute($quarterLaborP, 
                array($week->obfLaborQuarterID(), $labor->obfCategoryID(), date('Y-m-d 00:00:00', $end_ts))
            );
            if ($dbc->num_rows($quarter) == 0) {
                $quarter = array('hours'=>0, 'wages'=>0, 'laborTarget'=>0, 'hoursTarget'=>0);
            } else {
                $quarter = $dbc->fetch_row($quarter);
            }
            $qt_average_wage = $quarter['hours'] == 0 ? 0 : $quarter['wages'] / ((float)$quarter['hours']);
            $qt_proj_hours = $total_sales->quarterProjected / $c->salesPerLaborHourTarget();
            $qt_proj_labor = $qt_proj_hours * $qt_average_wage;
            $total_hours->quarterActual += $quarter['hours'];
            $total_hours->quarterProjected += $qt_proj_hours;

            $average_wage = 0;
            if ($labor->hours() != 0) {
                $average_wage = $labor->wages() / ((float)$labor->hours());
            }
            // use SPLH instead of pre-allocated
            $proj_hours = $total_sales->projected / $c->salesPerLaborHourTarget();
            $proj_wages = $proj_hours * $average_wage;

            $trend_hours = $total_sales->trend / $c->salesPerLaborHourTarget();
            $trend_wages = $trend_hours * $average_wage;

            $data[] = array(
                'Hours',
                '',
                number_format($proj_hours, 0),
                '',
                number_format($trend_hours, 0),
                number_format($labor->hours(), 0),
                '',
                '',
                number_format($labor->hours() - $proj_hours, 0),
                number_format($quarter['hours'] - $qt_proj_hours, 0),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );
            $qtd_hours_ou += ($quarter['hours'] - $qt_proj_hours);

            $quarter_actual_sph = $quarter['hours'] == 0 ? 0 : ($total_sales->quarterActual)/($quarter['hours']);
            $quarter_proj_sph = $qt_proj_hours == 0 ? 0 : ($total_sales->quarterProjected)/($qt_proj_hours);
            $data[] = array(
                'Sales per Hour',
                '',
                sprintf('%.2f', $total_sales->projected / $proj_hours),
                '',
                sprintf('%.2f', $total_sales->trend / $trend_hours),
                number_format($labor->hours() == 0 ? 0 : $total_sales->thisYear / $labor->hours(), 2),
                '',
                '',
                number_format(($labor->hours() == 0 ? 0 : $total_sales->thisYear/$labor->hours()) - ($total_sales->projected / $proj_hours), 2),
                number_format($quarter_actual_sph - $quarter_proj_sph, 2),
                'meta' => FannieReportPage::META_COLOR,
                'meta_background' => $colors[0],
                'meta_foreground' => 'black',
            );

            $total_hours->actual += $labor->hours();
            $total_hours->projected += $proj_hours;
            $total_hours->trend += $trend_hours;

            if (count($colors) > 1) {
                array_shift($colors);
            }
        }

        $data[] = array('meta'=>FannieReportPage::META_REPEAT_HEADERS);
        /**
           Organization totals section
        */
        $data[] = array('Total Organization', '', '', '', '', '', '', '', '', '',
                        'meta' => FannieReportPage::META_BOLD | FannieReportPage::META_COLOR,
                        'meta_background' => $colors[0],
                        'meta_foreground' => 'black',
        );

        $data[] = array(
            'Hours',
            '',
            number_format($total_hours->projected, 0),
            '',
            number_format($total_hours->trend, 0),
            number_format($total_hours->actual, 0),
            '',
            '',
            number_format($total_hours->actual - $total_hours->projected, 0),
            number_format($qtd_hours_ou, 0),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $quarter_actual_sph = $total_hours->quarterActual == 0 ? 0 : ($total_sales->quarterActual)/($total_hours->quarterActual);
        $quarter_proj_sph = $total_hours->quarterProjected == 0 ? 0 : ($total_sales->quarterProjected)/($total_hours->quarterProjected);
        $data[] = array(
            'Sales per Hour',
            '',
            sprintf('%.2f', $total_sales->projected / $total_hours->projected),
            '',
            sprintf('%.2f', $total_sales->trend / $total_hours->trend),
            number_format($total_hours->actual == 0 ? 0 : $total_sales->thisYear / $total_hours->actual, 2),
            '',
            '',
            number_format(($total_hours->actual == 0 ? 0 : $total_sales->thisYear/$total_hours->actual) - ($total_sales->projected/$total_hours->projected), 2),
            number_format($quarter_actual_sph - $quarter_proj_sph, 2),
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        $data[] = array('meta'=>FannieReportPage::META_REPEAT_HEADERS);

        $stockP = $dbc->prepare('
            SELECT SUM(stockPurchase) AS ttl
            FROM ' . $this->config->get('TRANS_DB') . $dbc->sep() . 'stockpurchases
            WHERE tdate BETWEEN ? AND ?
                AND dept=992
                AND trans_num NOT LIKE \'1001-30-%\'
        ');

        $args1 = array(
            date('Y-07-01 00:00:00', $end_ts),
            date('Y-m-d 23:59:59', $end_ts),
        );
        if (date('n', $end_ts) < 7) {
            $args1[0] = (date('Y', $end_ts) - 1) . '-07-01 00:00:00';
        }

        $last_year = mktime(0, 0, 0, date('n',$end_ts), date('j',$end_ts), date('Y',$end_ts)-1);
        $args2 = array(
            date('Y-07-01 00:00:00', $last_year),
            date('Y-m-d 23:59:59', $last_year),
        );
        if (date('n', $last_year) < 7) {
            $args2[0] = (date('Y', $last_year) - 1) . '-07-01 00:00:00';
        }

        $args3 = array(
            date('Y-m-d 00:00:00', $start_ts),
            date('Y-m-d 23:59:59', $end_ts),
        );
        $args4 = array(
            date('Y-m-d 00:00:00', $start_ly),
            date('Y-m-d 23:59:59', $end_ly),
        );

        $current = $dbc->execute($stockP, $args1);
        $prior = $dbc->execute($stockP, $args2);
        $this_week = $dbc->execute($stockP, $args3);
        $last_week = $dbc->execute($stockP, $args4);
        if ($dbc->num_rows($current) > 0) {
            $current = $dbc->fetch_row($current);
            $current = $current['ttl'] / 20;
        } else {
            $current = 0;
        }
        if ($dbc->num_rows($prior) > 0) {
            $prior = $dbc->fetch_row($prior);
            $prior = $prior['ttl'] / 20;
        } else {
            $prior = 0;
        }
        if ($dbc->num_rows($this_week) > 0) {
            $this_week = $dbc->fetch_row($this_week);
            $this_week = $this_week['ttl'] / 20;
        } else {
            $this_week = 0;
        }
        if ($dbc->num_rows($last_week) > 0) {
            $last_week = $dbc->fetch_row($last_week);
            $last_week = $last_week['ttl'] / 20;
            $num_days = (float)date('t', $start_ly);
            $last_week = round(($last_week/$num_days) * 7);
        } else {
            $last_week = 0;
        }

        $data[] = array(
            'Ownership This Week',
            number_format($this_week, 0),
            number_format($last_week, 0),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );
        $data[] = array(
            'Ownership This Year',
            number_format($current, 0),
            number_format($prior, 0),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'meta' => FannieReportPage::META_COLOR,
            'meta_background' => $colors[0],
            'meta_foreground' => 'black',
        );

        return $data;
    }
}

FannieDispatch::conditionalExec();
