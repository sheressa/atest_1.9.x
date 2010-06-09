<?php
namespace core;
/**
 * A database class
 *
 * @version $Id$
 * @author  Matt McNaney <mcnaney at gmail dot com>
 * @package Core
 */
require_once 'DB.php';

// Changing LOG_DB to true will cause ALL DB traffic to get logged
// This can log can get very large, very fast. DO NOT enable it
// on a live server. It is for development purposes only.
define('LOG_DB', false);

define('DEFAULT_MODE', DB_FETCHMODE_ASSOC);

// Removes dsn from log after failed database connection
define('CLEAR_DSN', true);

if (!defined('DB_ALLOW_TABLE_INDEX')) {
    define ('DB_ALLOW_TABLE_INDEX', true);
}

if (!defined('ALLOW_TABLE_LOCKS')) {
    define('ALLOW_TABLE_LOCKS', false);
}

class DB {
    public $tables      = null;
    public $where       = array();
    public $order       = array();
    public $values      = array();
    public $mode        = DEFAULT_MODE;
    public $limit       = null;
    public $index       = null;
    public $columns     = null;
    public $qwhere      = null;
    public $indexby     = null;
    public $force_array = false;
    public $ignore_dups = false;
    public $group_by    = null;
    public $locked      = null;

    /**
     * Holds module and class file names to be loaded on
     * the success of a loadObject or getObjects select query.
     */
    public $load_class  = null;

    /**
     * allows you to group together where queries
     */
    public $group_in    = array();
    // This variable holds a sql query string
    public $sql         = null;
    private $_allColumns = null;
    private $_columnInfo = null;
    private $_lock       = false;

    // contains the database specific factory class
    private $_distinct   = false;
    private $_test_mode  = false;
    private $_join       = null;
    private $_join_tables = null;
    public $table_as     = array();
    public $return_query = false;
    public $subselect_as = null;

    public function __construct($table=null)
    {
        DB::touchDB();
        if (isset($table)) {
            $result = $this->setTable($table);

            if (Error::isError($result)) {
                Error::log($result);
            }
        }
        $this->setMode('assoc');
    }

    /**
     * Lets you enter a raw select query
     */
    public function setSQLQuery($sql)
    {
        $this->sql = $sql;
    }
    public static function touchDB()
    {
        if (!DB::isConnected()) {
            return DB::loadDB();
        }
    }

    public function setTestMode($mode=true)
    {
        $this->_test_mode = (bool)$mode;
    }

    public static function isConnected()
    {
        if (!empty($GLOBALS['DB']['connection'])) {
            return true;
        } else {
            return false;
        }
    }

    public static function getDbName($dsn) {
        $aDSN = explode('/', $dsn);
        return array_pop($aDSN);
    }

    public static function _updateCurrent($key)
    {
        $GLOBALS['DB']['lib']        = $GLOBALS['DB']['dbs'][$key]['lib'];
        $GLOBALS['DB']['dsn']        = & $GLOBALS['DB']['dbs'][$key]['dsn'];
        $GLOBALS['DB']['connection'] = $GLOBALS['DB']['dbs'][$key]['connection'];
        $GLOBALS['DB']['tbl_prefix'] = & $GLOBALS['DB']['dbs'][$key]['tbl_prefix'];
        $GLOBALS['DB']['type']       = & $GLOBALS['DB']['dbs'][$key]['type'];
    }

    public static function loadDB($dsn=null, $tbl_prefix=null, $force_reconnect=false, $show_error=true)
    {
        if (!isset($dsn)) {
            if (!defined('PHPWS_DSN')) {
                exit(_('Cannot load database. DSN not defined.'));
            }

            $dsn = PHPWS_DSN;
            if (defined('PHPWS_TABLE_PREFIX')) {
                $tbl_prefix = PHPWS_TABLE_PREFIX;
            }
        }

        $key = substr(md5($dsn . $tbl_prefix), 0, 10);
        $dbname = DB::getDbName($dsn);
        $GLOBALS['DB']['key'] = $key;

        if (!empty($GLOBALS['DB']['dbs'][$key]['connection']) && !$force_reconnect) {
            DB::_updateCurrent($key);
            return true;
        }

        $pear_db = new \DB;
        $connect = $pear_db->connect($dsn);

        if (Error::isError($connect)) {
            if (CLEAR_DSN) {
                $connect->userinfo = str_replace($dsn, '-- DSN removed --', $connect->userinfo);
            }
            Error::log($connect);
            if ($show_error) {
                PHPWS_Core::errorPage();
            } else {
                return $connect;
            }
        }

        DB::logDB(sprintf(_('Connected to database "%s"'), $dbname));

        // Load the factory files
        $type = $connect->dbsyntax;
        $result = PHPWS_Core::initCoreClass('DB/' . $type .'.php');
        if ($result == false) {
            DB::logDB(_('Failed to connect.'));
            Error::log(File_NOT_FOUND, 'core', 'DB::loadDB',
            PHPWS_SOURCE_DIR . 'core/class/DB/' . $type . '.php');
            PHPWS_Core::errorPage();
        }

        $class_name = $type . '_PHPWS_SQL';
        $dblib = new $class_name;
        if (!empty($dblib->portability)) {
            $connect->setOption('portability', $dblib->portability);
        }

        $GLOBALS['DB']['dbs'][$key]['lib']        = $dblib;
        $GLOBALS['DB']['dbs'][$key]['dsn']        = $dsn;
        $GLOBALS['DB']['dbs'][$key]['connection'] = $connect;
        $GLOBALS['DB']['dbs'][$key]['tbl_prefix'] = $tbl_prefix;
        $GLOBALS['DB']['dbs'][$key]['type']       = $type;

        DB::_updateCurrent($key);

        return true;
    }

    public static function logDB($sql)
    {
        if (!defined('LOG_DB') || LOG_DB != true) {
            return;
        }

        PHPWS_Core::log($sql, 'db.log');
    }

    public static function query($sql, $prefix=true)
    {
        DB::touchDB();
        if ($prefix) {
            $sql = DB::prefixQuery($sql);
        }

        DB::logDB($sql);

        return $GLOBALS['DB']['connection']->query($sql);
    }

    public function getColumnInfo($col_name, $parsed=false)
    {
        if (!isset($this->_columnInfo)) {
            $this->getTableColumns();
        }

        if (isset($this->_columnInfo[$col_name])) {
            if ($parsed == true) {
                return $this->parsePearCol($this->_columnInfo[$col_name], true);
            } else {
                return $this->_columnInfo[$col_name];
            }
        }
        else {
            return null;
        }
    }

    public function inDatabase($table, $column=null)
    {
        $table = DB::addPrefix(strip_tags($table));

        DB::touchDB();
        static $database_info = null;

        $column = trim($column);
        $answer = false;

        if (!empty($database_info[$table])) {
            if (empty($column)) {
                return true;
            } else {
                return in_array($column, $database_info[$table]);
            }
        }

        $result = $GLOBALS['DB']['connection']->tableInfo($table);
        if (Error::isError($result)) {
            if ($result->getCode() == DB_ERROR_NEED_MORE_DATA) {
                return false;
            } else {
                return $result;
            }
        }

        if (empty($column)) {
            return true;
        }

        foreach ($result as $colInfo) {
            $list_columns[] = $colInfo['name'];

            if ($colInfo['name'] == $column) {
                $answer = true;
            }
        }

        $database_info[$table] = $list_columns;

        return $answer;
    }

    /**
     * Gets information on all the columns in the current table
     */
    public function getTableColumns($fullInfo=false)
    {
        static $table_check = null;

        $table_compare = implode(':', $this->tables);

        if (!$table_check || $table_check == $table_compare) {
            if (isset($this->_allColumns) && $fullInfo == false) {
                return $this->_allColumns;
            } elseif (isset($this->_columnInfo) && $fullInfo == true) {
                return $this->_columnInfo;
            }
        }

        $table_check = $table_compare;
        foreach ($this->tables as $table) {
            if (!isset($table)) {
                return Error::get(DB_ERROR_TABLE, 'core', 'DB::getTableColumns');
            }

            $table = $this->addPrefix($table);

            $columns =  $GLOBALS['DB']['connection']->tableInfo($table);

            if (Error::isError($columns)) {
                Error::log('Could not get columns in table: ' . $table);
                Error::log($columns);
                return $columns;
            }

            foreach ($columns as $colInfo) {
                $col_name = & $colInfo['name'];
                $this->_columnInfo[$col_name] = $colInfo;
                $this->_allColumns[$col_name] = $col_name;
            }
        }

        if ($fullInfo == true) {
            return $this->_columnInfo;
        } else {
            return $this->_allColumns;
        }
    }

    /**
     * Returns true is the columnName is contained in the
     * current table
     */
    public function isTableColumn($column_name)
    {
        $columns = $this->getTableColumns();
        if (Error::isError($columns)) {
            return $columns;
        }
        if (strpos($column_name, '.')) {
            $a = explode('.', $column_name);
            $column_name = array_pop($a);
        }

        return in_array($column_name, $columns);
    }

    public function setMode($mode)
    {
        switch (strtolower($mode)){
            case 'ordered':
                $this->mode = DB_FETCHMODE_ORDERED;
                break;

            case 'object':
                $this->mode = DB_FETCHMODE_OBJECT;
                break;

            case 'assoc':
                $this->mode = DB_FETCHMODE_ASSOC;
                break;
        }

    }

    public function getMode()
    {
        return $this->mode;
    }

    public static function isTable($table)
    {
        DB::touchDB();
        $tables = DB::listTables();

        $table = DB::addPrefix($table);
        return in_array($table, $tables);
    }

    public static function listTables()
    {
        DB::touchDB();
        return $GLOBALS['DB']['connection']->getlistOf('tables');
    }

    public function listDatabases()
    {
        DB::touchDB();
        return $GLOBALS['DB']['connection']->getlistOf('databases');
    }

    public function addJoin($join_type, $join_from, $join_to, $join_on_1=null, $join_on_2=null, $ignore_tables=false)
    {
        if (!preg_match('/left|right/i', $join_type)) {
            return false;
        }
        $this->_join_tables[] = array('join_type' => $join_type,
                                      'join_from' => $join_from,
                                      'join_to'   => $join_to,
                                      'join_on_1' => $join_on_1,
                                      'join_on_2' => $join_on_2,
                                      'ignore_tables' => $ignore_tables);
    }

    public function addTable($table, $as=null)
    {
        if (is_array($table)) {
            foreach ($table as $tbl_name) {
                $this->addTable($tbl_name);
            }
            return;
        }
        if (DB::allowed($table)) {
            if ($as) {
                $this->table_as[$as] = $table;
            } elseif (empty($this->tables) || !in_array($table, $this->tables)) {
                $this->tables[] = $table;
            }
        } else {
            return Error::get(DB_BAD_TABLE_NAME, 'core', 'DB::addTable', $table);
        }
        return true;
    }

    public function setTable($table)
    {
        $this->tables = array();
        $this->_join_tables = null;
        $this->_columnInfo = null;
        $this->_allColumns = null;
        return $this->addTable($table);
    }

    public function setIndex($index)
    {
        $this->index = $index;
    }

    public function getIndex($table=null)
    {
        if (isset($this->index)) {
            return $this->index;
        }

        if (empty($table)) {
            $table = $this->getTable(false);
        }

        $table = $this->addPrefix($table);

        $columns =  $GLOBALS['DB']['connection']->tableInfo($table);

        if (Error::isError($columns)) {
            return $columns;
        }

        foreach ($columns as $colInfo) {
            if ($colInfo['name'] == 'id' && preg_match('/primary/', $colInfo['flags']) && preg_match('/int/', $colInfo['type'])) {
                return $colInfo['name'];
            }
        }

        return null;
    }

    public function _getJoinOn($join_on_1, $join_on_2, $table1, $table2, $ignore_tables=false) {
        if (empty($join_on_1) || empty($join_on_2)) {
            return null;
        }

        if (is_object($table1) && get_class($table1) == 'DB') {
            if (empty($table1->subselect_as)) {
                return null;
            }
            $table1->return_query = true;
            $this->table_as[$table1->subselect_as] = sprintf('(%s)', $table1->select());
            $table1 = $table1->subselect_as;
        }

        if (is_object($table2) && get_class($table2) == 'DB') {
            if (empty($table2->subselect_as)) {
                return null;
            }

            $table2->return_query = true;
            $this->table_as[$table2->subselect_as] = sprintf('(%s)', $table2->select());
            $table2 = $table2->subselect_as;
        }

        if (is_array($join_on_1) && is_array($join_on_2)) {
            foreach ($join_on_1 as $key => $value) {
                if ($ignore_tables || preg_match('/\w\.\w/', $value)) {
                    $value1 = & $value;
                } else {
                    $value1 = $table1 . '.' . $value;
                }

                if ($ignore_tables || preg_match('/\w\.\w/', $join_on_2[$key])) {
                    $value2 = & $join_on_2[$key];
                } else {
                    $value2 = $table2 . '.' . $join_on_2[$key];
                }

                $retVal[] = sprintf('%s = %s', $value1, $value2);
            }
            return implode(' AND ', $retVal);
        } else {
            return sprintf('%s.%s = %s.%s',
            $table1, $join_on_1,
            $table2, $join_on_2);
        }
    }

    public function getJoin()
    {
        if (empty($this->_join_tables)) {
            return null;
        }

        $join_info['tables'] = array();

        foreach ($this->_join_tables as $join_array) {
            $dup = md5(serialize($join_array));
            if (isset($dup_list) && in_array($dup, $dup_list)) {
                continue;
            }
            $dup_list[] = $dup;
            extract($join_array);

            if ($result = $this->_getJoinOn($join_on_1, $join_on_2, $join_from, $join_to, $ignore_tables)) {
                $join_on = 'ON ' . $result;
            }

            if (is_object($join_to) && get_class($join_to) == 'DB') {
                if (empty($join_to->subselect_as)) {
                    return null;
                }
                $join_to = $join_to->subselect_as;
            }

            if (is_object($join_from) && get_class($join_from) == 'DB') {
                if (empty($join_from->subselect_as)) {
                    return null;
                }
                $join_from = $join_from->subselect_as;
            }

            if (isset($this->table_as[$join_to])) {
                $join_to = sprintf('%s as %s', $this->table_as[$join_to], $join_to);
            }

            if (isset($this->table_as[$join_from])) {
                $join_from = sprintf('%s as %s', $this->table_as[$join_from], $join_from);
            }

            if (in_array($join_from, $join_info['tables'])) {
                $allJoin[] = sprintf('%s %s %s',
                strtoupper($join_type) . ' JOIN',
                $join_to,
                $join_on);
            } elseif (in_array($join_to, $join_info['tables'])) {
                $allJoin[] = sprintf('%s %s %s',
                strtoupper($join_type) . ' JOIN',
                $join_from,
                $join_on);
            } else {
                $allJoin[] = sprintf('%s %s %s %s',
                $join_from,
                strtoupper($join_type) . ' JOIN',
                $join_to,
                $join_on);
            }

            $join_info['tables'][] = $join_from;
            $join_info['tables'][] = $join_to;
        }

        $join_info['join'] = implode(' ', $allJoin);

        return $join_info;
    }

    /**
     * if format is true, all tables in the array are returned. This
     * is used for select queries. If false, the first table is popped
     * off and returned
     */
    public function getTable($format=true)
    {
        if (empty($this->tables)) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::getTable');
        }

        if ($format == true) {
            $join_info = $this->getJoin();

            foreach ($this->tables as $table) {
                if ($join_info && in_array($table, $join_info['tables'])) {
                    continue;
                }

                $table_list[] = $table;
            }

            if ($join_info) {
                $table_list[] = $join_info['join'];
            } elseif (!empty($this->table_as)) {
                foreach ($this->table_as as $sub => $table) {
                    $table_list[] = sprintf('%s as %s', $table, $sub);
                }
            }
            return implode(',', $table_list);
        } else {
            foreach ($this->tables as $table);
            return $table;
        }
    }

    public function resetTable()
    {
        $this->tables = array();
    }

    public function setGroupConj($group, $conj)
    {
        $conj = strtoupper($conj);
        if (empty($conj) || ($conj != 'OR' &&  $conj != 'AND')) {
            return false;
        }

        $this->where[$group]['conj'] = $conj;
    }

    public function addGroupBy($group_by)
    {
        if (DB::allowed($group_by)) {
            if (!strpos($group_by, '.')) {
                $group_by = $this->tables[0] . '.' . $group_by;
            }

            if (empty($this->group_by) || !in_array($group_by, $this->group_by)) {
                $this->group_by[] = $group_by;
            }
        }
        return true;
    }

    public function getGroupBy($dbReady=false)
    {
        if ((bool)$dbReady == true) {
            if (empty($this->group_by)) {
                return null;
            } else {
                return 'GROUP BY ' . implode(', ', $this->group_by);
            }
        }
        return $this->group_by;
    }

    /**
     * Puts the first group label into the second
     */
    public function groupIn($sub, $main)
    {
        $group_names = array_keys($this->where);
        if (!in_array($sub, $group_names) || !in_array($main, $group_names)) {
            return false;
        }
        $this->group_in[$sub] = $main;
        return true;
    }


    public function addWhere($column, $value=null, $operator=null, $conj=null, $group=null, $join=false)
    {
        DB::touchDB();
        $where = new DB_Where;
        $where->setJoin($join);
        $operator = strtoupper($operator);
        if (is_array($column)) {
            foreach ($column as $new_column => $new_value) {
                $result = $this->addWhere($new_column, $new_value, $operator, $conj, $group);
                if (Error::isError($result)) {
                    return $result;
                }
            }
            return true;
        } else {
            if (!DB::allowed($column) || preg_match('[^\w\.]', $column)) {
                return Error::get(DB_BAD_COL_NAME, 'core',
                                        'DB::addWhere', $column);
            }
        }

        if (is_array($value) && !empty($value)) {
            if (!empty($operator) && $operator != 'IN' && $operator != 'NOT IN' &&
            $operator != 'BETWEEN' && $operator != 'NOT BETWEEN') {
                $search_in = true;
            } else {
                if (empty($operator)) {
                    $operator = 'IN';
                }
                $search_in = false;
            }

            foreach ($value as $newVal) {
                if ($search_in) {
                    $result = $this->addWhere($column, $newVal, $operator, $conj, $group);
                    if (Error::isError($result)) {
                        return $result;
                    }
                } else {
                    $newVal = $GLOBALS['DB']['connection']->escapeSimple($newVal);
                    $new_value_list[] = $newVal;
                }
            }

            if (!$search_in && isset($new_value_list)) {
                $value = &$new_value_list;
            } else {
                return true;
            }
        } else {
            if (is_null($value) || (is_string($value) && strtoupper($value) == 'NULL')) {
                if (empty($operator) || ( $operator != 'IS NOT' && $operator != '!=')) {
                    $operator = 'IS';
                } else {
                    $operator = 'IS NOT';
                }
                $value = 'NULL';
            } else {
                $value = $GLOBALS['DB']['connection']->escapeSimple($value);
            }
        }

        $source_table = $this->tables[0];

        if (is_string($column)) {
            if (substr_count($column, '.') == 1) {
                list($join_table, $join_column) = explode('.', $column);

                if (isset($this->table_as[$join_table])) {
                    $source_table = $join_table;
                    $column = & $join_column;
                } elseif (DB::inDatabase($join_table, $join_column)) {
                    $column = & $join_column;
                    $source_table = $join_table;
                    $this->addTable($join_table);
                }
            }
        }

        $where->setColumn($column);
        $where->setTable($source_table);

        if (is_string($value)) {
            if (substr_count($value, '.') == 1) {
                list($join_table, $join_column) = explode('.', $value);
                if (isset($this->table_as[$join_table])) {
                    $where->setJoin(true);
                } elseif ($this->inDatabase($join_table, $join_column)) {
                    $where->setJoin(true);
                    $this->addTable($join_table);
                }
            }
        }

        $where->setValue($value);
        $where->setConj($conj);
        $where->setOperator($operator);

        if (isset($group)) {
            $this->where[$group]['values'][] = $where;
        }
        else {
            $this->where[0]['values'][] = $where;
        }

    }


    public static function checkOperator($operator)
    {
        $allowed = array('>',
                         '>=',
                         '<',
                         '<=',
                         '=',
                         '!=',
                         '<>',
                         '<=>',
                         'LIKE',
                         'ILIKE',
                         'NOT LIKE',
                         'NOT ILIKE',
                         'REGEXP',
                         'RLIKE',
                         'IN',
                         'NOT IN',
                         'BETWEEN',
                         'NOT BETWEEN',
                         'IS',
                         'IS NOT',
                         '~');

        return in_array(strtoupper($operator), $allowed);
    }

    public function setQWhere($where, $conj='AND')
    {
        $conj = strtoupper($conj);
        if (empty($conj) || ($conj != 'OR' &&  $conj != 'AND')) {
            return false;
        }

        $where = preg_replace('/where/i', '', $where);
        $this->qwhere['where'] = $where;
        $this->qwhere['conj']  = $conj;
    }


    /**
     * Grabs the where variables from the object and creates a sql query
     */
    public function getWhere($dbReady=false)
    {
        $sql = array();
        $ignore_list = $where = null;

        if (empty($this->where)) {
            if (isset($this->qwhere)) {
                return ' (' . $this->qwhere['where'] .')';
            }
            return null;
        }
        $startMain = false;
        if ($dbReady) {
            foreach ($this->where as $group_name => $groups) {
                $hold = null;
                $subsql = array();
                if (!isset($groups['values'])) {
                    continue;
                }

                $startSub = false;

                foreach ($groups['values'] as $whereVal) {
                    if ($startSub == true) {
                        $subsql[] = $whereVal->conj;
                    }
                    $subsql[] = $whereVal->get();
                    $startSub = true;
                }

                $where_list[$group_name]['group_sql'] = $subsql;

                if (@$conj = $groups['conj']) {
                    $where_list[$group_name]['group_conj'] = $conj;
                } else {
                    $where_list[$group_name]['group_conj'] = 'AND';
                }

                if (@$search_key = array_search($group_name, $this->group_in, true)) {
                    $where_list[$search_key]['group_in'][$group_name] = &$where_list[$group_name];
                }
            }

            $start_main = false;

            if (!empty($where_list)) {
                $sql[] = $this->_buildGroup($where_list, $ignore_list, true);
            }

            if (isset($this->qwhere)) {
                $sql[] = $this->qwhere['conj'] . ' (' . $this->qwhere['where'] . ')';
            }

            if (isset($sql)) {
                $where = implode(' ', $sql);
            }

            return $where;
        } else {
            return $this->where;
        }
    }

    /**
     * Handles the imbedding of where groups
     */
    public function _buildGroup($where_list, &$ignore_list, $first=false) {
        if (!$ignore_list) {
            $ignore_list = array();
        }

        foreach ($where_list as $group_name => $group_info) {
            if (isset($ignore_list[$group_name])) {
                continue;
            }
            $ignore_list[$group_name] = true;
            extract($group_info);

            if (!$first) {
                $sql[] = $group_conj;
            } else {
                $first=false;
            }

            if (!empty($group_in)) {
                $sql[] = '( ( ' . implode(' ', $group_sql) . ' )';
                $result = $this->_buildGroup($group_in, $ignore_list);
                if ($result) {
                    $sql[] = $result;
                }
                $sql[] = ' )';
            } else {
                $sql[] = '( ' . implode(' ', $group_sql) . ' )';
            }
        }
        if (!empty($sql)) {
            return implode (' ', $sql);
        }
    }

    public function resetWhere()
    {
        $this->where = array();
    }

    public function isDistinct()
    {
        return (bool)$this->_distinct;
    }

    public function setDistinct($distinct=true)
    {
        $this->_distinct = (bool)$distinct;
    }

    public function addColumn($column, $max_min=null, $as=null, $count=false, $distinct=false, $coalesce=null)
    {

        if (preg_match('/[^\w\.*]/', $column)) {
            return false;
        }

        if (!in_array(strtolower($max_min), array('max', 'min'))) {
            $max_min = null;
        }

        $table = $this->tables[0];
        if (strpos($column, '.')) {
            list($table, $column) = explode('.', $column);
            if (!isset($this->table_as[$table])) {
                $this->addTable($table);
            }
        }

        if (!empty($as)) {
            if (!DB::allowed($as)) {
                return Error::get(DB_BAD_COL_NAME, 'core', 'DB::addColumn', $as);
            }
        }

        if (!DB::allowed($column)) {
            return Error::get(DB_BAD_COL_NAME, 'core', 'DB::addColumn', $column);
        }

        if ($distinct && !$count) {
            $this->addGroupBy($table . '.' . $column);
        }

        $col['table']    = $table;
        $col['name']     = $column;
        $col['max_min']  = $max_min;
        $col['count']    = (bool)$count;
        $col['distinct'] = (bool)$distinct;
        $col['coalesce'] = $coalesce;
        if ($column != '*') {
            $col['as']       = $as;
        }

        $this->columns[] = $col;
    }

    public function getAllColumns()
    {
        $columns[] = $this->getColumn(true);
        return $columns;
    }

    public function getColumn($format=false)
    {
        if ($format) {
            if (empty($this->columns)) {
                return $this->tables[0] . '.*';
            } else {
                foreach ($this->columns as $col) {
                    $as = null;
                    extract($col);
                    if ($count) {
                        if ($distinct) {
                            $table_name = sprintf('count(distinct(%s.%s))', $table, $name);
                        } else {
                            $table_name = sprintf('count(%s.%s)', $table, $name);
                        }
                    } else if(!is_null($coalesce)) {
                        if($distinct) {
                            $table_name = sprintf('coalesce(distinct(%s.%s), %s)', $table, $name, $coalesce);
                        } else {
                            $table_name = sprintf('coalesce(%s.%s, %s)', $table, $name, $coalesce);
                        }
                    } else {
                        if ($distinct) {
                            $table_name = sprintf('distinct(%s.%s)', $table, $name);
                        } else {
                            $table_name = sprintf('%s.%s', $table, $name);
                        }
                    }
                    if ($max_min) {
                        $table_name = strtoupper($max_min) . "($table_name)";
                    }
                    if (!empty($as)) {
                        $columns[] = "$table_name AS $as";
                    } else {
                        $columns[] = "$table_name";
                    }
                }
                return implode(', ', $columns);
            }
        } else {
            return $this->columns;
        }
    }

    /**
     * Sets the result array key to the value of the indexby column.
     * If you expect multiple results per index, you may wish to set
     * force_array to true. This will ensure the results per line are always
     * an array of results.
     *
     * For example, if you group by a foreign key you may get 2 results on one
     * index a only one on the other. Here is an example array were that the result:
     *
     * 'cat' => 0 => 'Whiskers'
     *          1 => 'Muffin'
     * 'dog' => 'Rover'
     *
     * If you knew that repeats were possible and set force_array to true, this
     * would be the result instead:
     *
     * 'cat' => 0 => 'Whiskers'
     *          1 => 'Muffin'
     * 'dog' => 0 => 'Rover'
     *
     */
    public function setIndexBy($indexby, $force_array=false, $ignore_dups=false)
    {
        if (strstr($indexby, '.')) {
            $indexby = substr($indexby, strpos($indexby, '.') + 1);
        }
        $this->indexby = $indexby;
        $this->force_array = (bool)$force_array;
        $this->ignore_dups = (bool)$ignore_dups;
    }

    public function getIndexBy()
    {
        return $this->indexby;
    }

    /**
     * Allows you to add an order or an array of orders to
     * a db query
     *
     * sending random or rand with or without the () will query random
     * element
     */

    public function addOrder($order)
    {
        if (is_array($order)) {
            foreach ($order as $value) {
                $this->addOrder($value);
            }
        } else {
            $order = preg_replace('/[^\w\s\.]/', '', $order);

            if (preg_match('/(random|rand)(\(\))?/i', $order)) {
                $this->order[] = $GLOBALS['DB']['lib']->randomOrder();
            } else {
                if (strpos($order, '.')) {
                    list($table, $new_order) = explode('.', $order);
                    $this->order[] = array('table' => $table, 'column' => $new_order);
                } else {
                    $this->order[] = array('table' => $this->tables[0], 'column' => $order);
                }
            }
        }
    }

    public function getOrder($dbReady=false)
    {
        if (empty($this->order)) {
            return null;
        }

        if ($dbReady) {
            foreach ($this->order as $aOrder) {
                if (is_array($aOrder)) {
                    $order_list[] = $aOrder['table'] . '.' . $aOrder['column'];
                } else {
                    // for random orders
                    $order_list[] = $aOrder;
                }
            }
            return 'ORDER BY ' . implode(', ', $order_list);
        } else {
            return $this->order;
        }
    }

    public function resetOrder()
    {
        $this->order = array();
    }

    public function addValue($column, $value=null)
    {
        if (is_array($column)) {
            foreach ($column as $colKey=>$colVal){
                $result = $this->addValue($colKey, $colVal);
                if (Error::isError($result)) {
                    return $result;
                }
            }
        } else {
            if (!DB::allowed($column)) {
                return Error::get(DB_BAD_COL_NAME, 'core', 'DB::addValue', $column);
            }

            $this->values[$column] = $value;
        }
    }

    public function getValue($column)
    {
        if (empty($this->values) || !isset($this->values[$column])) {
            return null;
        }

        return $this->values[$column];
    }

    public function resetValues()
    {
        $this->values = array();
    }

    public function getAllValues()
    {
        if (!isset($this->values) || empty($this->values)) {
            return null;
        }

        return $this->values;
    }


    public function setLimit($limit, $offset=null)
    {
        unset($this->limit);

        if (is_array($limit)) {
            $_limit = $limit[0];
            $_offset = $limit[1];
        }
        elseif (preg_match('/,/', $limit)) {
            $split = explode(',', $limit);
            $_limit = trim($split[0]);
            $_offset = trim($split[1]);
        }
        else {
            $_limit = $limit;
            $_offset = $offset;
        }

        $this->limit['total'] = preg_replace('/[^\d\s]/', '', $_limit);

        if (isset($_offset)) {
            $this->limit['offset'] = preg_replace('/[^\d\s]/', '', $_offset);
        }

        return true;
    }

    public function getLimit($dbReady=false)
    {
        if (empty($this->limit)) {
            return null;
        }

        if ($dbReady) {
            return $GLOBALS['DB']['lib']->getLimit($this->limit);
        }
        else {
            return $this->limit;
        }
    }

    public function resetLimit()
    {
        $this->limit = '';
    }

    public function resetColumns()
    {
        $this->columns = null;
    }


    public function affectedRows()
    {
        $query =  DB::lastQuery();
        $process = strtolower(substr($query, 0, strpos($query, ' ')));

        if ($process == 'select') {
            return false;
        }

        return $GLOBALS['DB']['connection']->affectedRows();
    }

    /**
     * Resets where, values, limits, order, columns, indexby, and qwhere
     * Does NOT reset locked tables but does remove any tables beyond the
     * initiating one
     */
    public function reset()
    {
        $this->resetWhere();
        $this->resetValues();
        $this->resetLimit();
        $this->resetOrder();
        $this->resetColumns();
        $this->indexby = null;
        $this->qwhere  = null;
        $tmp_table = $this->tables[0];
        $this->tables = null;
        $this->tables = array($tmp_table);
    }

    public function lastQuery()
    {
        return $GLOBALS['DB']['connection']->last_query;
    }

    public function insert($auto_index=true)
    {
        DB::touchDB();
        $maxID = true;
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::insert');
        }

        $values = $this->getAllValues();

        if (!isset($values)) {
            return Error::get(DB_NO_VALUES, 'core', 'DB::insert');
        }

        if ($auto_index) {
            $idColumn = $this->getIndex();

            if (Error::isError($idColumn)) {
                return $idColumn;
            } elseif(isset($idColumn)) {
                $check_table = $this->addPrefix($table);
                $maxID = $GLOBALS['DB']['connection']->nextId($check_table);
                $values[$idColumn] = $maxID;
            }
        }

        foreach ($values as $index=>$entry){
            $columns[] = $index;
            $set[] = DB::dbReady($entry);
        }

        $query = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $set) . ')';

        $result = DB::query($query);

        if (Error::isError($result)) {
            return $result;
        } else {
            return $maxID;
        }
    }

    public function update($return_affected=false)
    {
        DB::touchDB();

        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::insert');
        }

        $values = $this->getAllValues();
        $where = $this->getWhere(true);

        if (!empty($where)) {
            $where = 'WHERE ' . $where;
        }

        if (empty($values)) {
            return Error::get(DB_NO_VALUES, 'core', 'DB::update');
        }

        foreach ($values as $index=>$data) {
            $columns[] = $index . ' = ' . DB::dbReady($data);
        }

        $limit = $this->getLimit(true);
        $order = $this->getOrder(true);

        $query = "UPDATE $table SET " . implode(', ', $columns) ." $where $order $limit";
        $result = DB::query($query);

        if (\PEAR::isError($result)) {
            return $result;
        } else {
            if ($return_affected) {
                return $this->affectedRows();
            } else {
                return true;
            }
        }
    }

    public function count()
    {
        return $this->select('count');
    }

    public function getSelectSQL($type)
    {
        if ($type == 'count' && empty($this->columns)) {
            $columns = null;
        } else {
            $columns = implode(', ', $this->getAllColumns());
        }

        $table = $this->getTable();

        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::select');
        }

        $where   = $this->getWhere(true);
        $order   = $this->getOrder(true);
        $limit   = $this->getLimit(true);
        $group_by = $this->getGroupBy(true);

        $sql_array['columns']  = & $columns;
        $sql_array['table']    = & $table;
        $sql_array['where']    = & $where;
        $sql_array['group_by'] = & $group_by;
        $sql_array['order']    = & $order;
        $sql_array['limit']    = & $limit;

        return $sql_array;
    }


    /**
     * Retrieves information from the database.
     * Select utilizes parameters set previously in the object
     * (i.e. addWhere, addColumn, setLimit, etc.)
     * You may also set the "type" of result: assoc (associative)
     * col (columns), min (minimum result), max (maximum result), one (a single column from a
     * single row), row (a single row), count (a tally of rows) or all, the default.
     * All returns an associate array containing the requested information.
     *
     */
    public function select($type=null, $sql=null)
    {
        if (empty($sql)) {
            if (!empty($this->sql)) {
                $sql = & $this->sql;
            }
        }
        DB::touchDB();
        if (isset($type) && is_string($type)) {
            $type = strtolower($type);
        }

        $mode = $this->getMode();
        $indexby = $this->getIndexBy();

        if (!isset($sql)) {
            $sql_array = $this->getSelectSQL($type);
            if (Error::isError($sql_array)) {
                return $sql_array;
            }

            // extract will get $columns, $table, $where, $group_by
            // $order, and $limit
            extract($sql_array);

            if ($type == 'count' || $type == 'count_array') {
                if (empty($columns)) {
                    // order and group_by are not needed if count is
                    // using all rows
                    $order = null;
                    $group_by = null;
                    $columns = 'COUNT(*)';
                } else {
                    $add_group = $columns;
                    $columns .= ', COUNT(*)';

                    if (empty($group_by)) {
                        $group_by = "GROUP BY $add_group";
                    }
                }
            }

            if (!empty($where)) {
                $where = 'WHERE ' . $where;
            }

            if ($this->isDistinct()) {
                $distinct = 'DISTINCT';
            } else {
                $distinct = null;
            }


            $sql = "SELECT $distinct $columns FROM $table $where $group_by $order $limit";
        } else {
            $mode = DB_FETCHMODE_ASSOC;
        }

        $sql = DB::prefixQuery($sql);

        if ($this->_test_mode) {
            exit($sql);
        }

        if ($this->return_query) {
            return trim($sql);
        }

        // assoc does odd things if the resultant return is two items or less
        // not sure why it is coded that way. Use the default instead

        switch ($type){
            case 'assoc':
                DB::logDB($sql);
                return $GLOBALS['DB']['connection']->getAssoc($sql, null,null, $mode);
                break;

            case 'col':
                if (empty($sql) && empty($this->columns)) {
                    return Error::get(DB_NO_COLUMN_SET, 'core', 'DB::select');
                }

                if (isset($indexby)) {
                    DB::logDB($sql);
                    $result = $GLOBALS['DB']['connection']->getAll($sql, null, $mode);

                    if (Error::isError($result)) {
                        return $result;
                    }
                    return DB::_indexBy($result, $indexby, true);
                }
                DB::logDB($sql);
                return $GLOBALS['DB']['connection']->getCol($sql);
                break;

            case 'min':
            case 'max':
            case 'one':
                DB::logDB($sql);
                return $GLOBALS['DB']['connection']->getOne($sql, null, $mode);
                break;

            case 'row':
                DB::logDB($sql);
                return $GLOBALS['DB']['connection']->getRow($sql, array(), $mode);
                break;

            case 'count':
                DB::logDB($sql);
                if (empty($this->columns)) {
                    $result = $GLOBALS['DB']['connection']->getRow($sql);
                    if (Error::isError($result)) {
                        return $result;
                    }
                    return $result[0];
                } else {
                    $result = $GLOBALS['DB']['connection']->getCol($sql);
                    if (Error::isError($result)) {
                        return $result;
                    }

                    return count($result);
                }
                break;

            case 'count_array':
                DB::logDB($sql);
                $result = $GLOBALS['DB']['connection']->getAll($sql, null, $mode);
                if (Error::isError($result)) {
                    return $result;
                }
                return $result;
                break;


            case 'all':
            default:
                DB::logDB($sql);
                $result = $GLOBALS['DB']['connection']->getAll($sql, null, $mode);
                if (Error::isError($result)) {
                    return $result;
                }

                if (isset($indexby)) {
                    return DB::_indexBy($result, $indexby);
                }

                return $result;
                break;
        }
    }

    public static function getRow($sql)
    {
        $db = new DB;
        return $db->select('row', $sql);
    }

    public static function getCol($sql)
    {
        $db = new DB;
        return $db->select('col', $sql);
    }

    public static function getAll($sql)
    {
        $db = new DB;
        return $db->select('all', $sql);
    }

    public static function getOne($sql)
    {
        $db = new DB;
        return $db->select('one', $sql);
    }

    public static function getAssoc($sql)
    {
        $db = new DB;
        return $db->select('assoc', $sql);
    }

    public function _indexBy($sql, $indexby, $colMode=false){
        $rows = array();

        if (!is_array($sql) || empty($sql)) {
            return $sql;
        }
        $stacked = false;

        foreach ($sql as $item) {
            if (!isset($item[(string)$indexby])) {
                return $sql;
            }

            if ($colMode) {
                $col = $this->getColumn();
                $value = $item[$indexby];
                unset($item[$indexby]);

                foreach ($col as $key=>$col_test) {
                    if ($col_test['name'] == $indexby) {
                        unset($col[$key]);
                        break;
                    }
                }

                $column = array_pop($col);
                if (isset($column['as'])) {
                    $col_check = $column['as'];
                } else {
                    $col_check = $column['name'];
                }

                if (isset($item[$col_check]) || $item[$col_check] === null ) {
                    DB::_expandIndex($rows, $value, $item[$col_check], $stacked);
                }
            } else {
                DB::_expandIndex($rows, $item[$indexby], $item, $stacked);
            }
        }

        return $rows;
    }

    public function _expandIndex(&$rows, $index, $item, &$stacked)
    {
        if ($this->force_array) {
            $rows[$index][] = $item;
        } elseif (isset($rows[$index]) && !$this->ignore_dups) {
            if (is_array($rows[$index]) && !isset($rows[$index][0])) {
                $hold = $rows[$index];
                $rows[$index] = array();
                $rows[$index][] = $hold;
                $stacked = true;
            }
            if (!$stacked) {
                $hold = $rows[$index];
                $rows[$index] = array();
                $rows[$index][] = $hold;
                $stacked = true;
            }
            if (!is_array($rows[$index])) {
                $i = $rows[$index];
                $rows[$index] = array();
                $rows[$index][] = $i;
            }
            $rows[$index][] = $item;
        } else {
            $rows[$index] = $item;
        }
    }

    /**
     * increases the value of a table column
     */
    public function incrementColumn($column_name, $amount=1)
    {
        $amount = (int)$amount;

        if ($amount == 0) {
            return true;
        }

        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::incrementColumn');
        }

        $where = $this->getWhere(true);
        if (!empty($where)) {
            $where = 'WHERE ' . $where;
        }

        if ($amount < 0) {
            $math = $amount;
        } else {
            $math = "+ $amount";
        }

        $query = "UPDATE $table SET $column_name = $column_name $math $where";
        $result = DB::query($query);

        if (DB::isError($result)) {
            return $result;
        } else {
            return true;
        }
    }

    /**
     * reduces the value of a table column
     */

    public function reduceColumn($column_name, $amount=1)
    {
        return $this->incrementColumn($column_name, ($amount * -1));
    }

    public function delete($return_affected=false)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::delete');
        }

        $where = $this->getWhere(true);
        $limit = $this->getLimit(true);
        $order = $this->getOrder(true);

        if (!empty($where)) {
            $where = 'WHERE ' . $where;
        }
        $sql = "DELETE FROM $table $where $order $limit";
        $result = DB::query($sql);

        if (DB::isError($result)) {
            return $result;
        } else {
            if ($return_affected) {
                return $this->affectedRows();
            } else {
                return true;
            }
        }

    }

    /**
     * Static call only
     * check_existence - of table
     * sequence_table  - if true, drop sequence table as well
     */
    public static function dropTable($table, $check_existence=true, $sequence_table=true)
    {
        DB::touchDB();

        // was using IF EXISTS but not cross compatible
        if ($check_existence && !DB::isTable($table)) {
            return true;
        }

        $result = DB::query("DROP TABLE $table");

        if (Error::isError($result)) {
            return $result;
        }

        if ($sequence_table && DB::isSequence($table)) {
            $result = $GLOBALS['DB']['lib']->dropSequence($table . '_seq');
            if (Error::isError($result)) {
                return $result;
            }
        }

        return true;
    }

    public static function isSequence($table)
    {
        $table = DB::addPrefix($table);
        return is_numeric($GLOBALS['DB']['connection']->nextId($table));
    }

    public function truncateTable()
    {
        $table = $this->getTable(false);
        if(!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::truncateTable()');
        }

        $sql = "TRUNCATE TABLE $table";

        return DB::query($sql);
    }

    public function dropTableIndex($name=null)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::dropTableIndex');
        }

        if (empty($name)) {
            $name = str_replace('_', '', $table) . '_idx';
        }
        $sql = $GLOBALS['DB']['lib']->dropTableIndex($name, $table);
        return $this->query($sql);
    }

    /**
     * Creates an index on a table. column variable can be a string or
     * an array of strings representing column names.
     * The name of the index is optional. The function will create one based
     * on the table name. Setting your index name might be a smart thing to do
     * in case you ever need to DROP it.
     */
    public function createTableIndex($column, $name=null, $unique=false)
    {
        if(!DB_ALLOW_TABLE_INDEX) {
            return false;
        }

        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::createTableIndex');
        }

        if (is_array($column)) {
            foreach ($column as $col) {
                if (!$this->isTableColumn($col)) {
                    return Error::get(DB_BAD_COL_NAME, 'core', 'DB::createTableIndex');
                }
            }
            $column = implode(',', $column);
        } else {
            if (!$this->isTableColumn($column)) {
                return Error::get(DB_BAD_COL_NAME, 'core', 'DB::createTableIndex');
            }
        }

        if (empty($name)) {
            $name = str_replace('_', '', $table) . '_idx';
        }

        if ($unique) {
            $unique_idx = 'UNIQUE ';
        } else {
            $unique_idx = ' ';
        }

        $sql = sprintf('CREATE %sINDEX %s ON %s (%s)', $unique_idx, $name, $table, $column);

        return $this->query($sql);
    }

    public function createPrimaryKey($column='id')
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::createTableIndex');
        }
        $sql = sprintf('alter table %s add primary key(%s)', $table, $column);
        return $this->query($sql);
    }

    public function createTable()
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::createTable');
        }

        $values = $this->getAllValues();

        foreach ($values as $column=>$value) {
            $parameters[] = $column . ' ' . $value;
        }


        $sql = "CREATE TABLE $table ( " . implode(', ', $parameters) . ' )';
        return DB::query($sql);
    }

    /**
     * Renames a table column
     * Because databases disagree on their commands to change column
     * names, this function requires different factory files.
     * Factory files must handle the prefixing.
     */
    public function renameTableColumn($old_name, $new_name)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::renameTableColumn');
        }

        $specs = $this->getColumnInfo($old_name, true);
        if (empty($specs)) {
            return Error::get(DB_BAD_COL_NAME, 'core', 'DB::renameTableColumn', $old_name);
        }

        $sql = $GLOBALS['DB']['lib']->renameColumn($table, $old_name, $new_name, $specs);

        return $this->query($sql, false);
    }

    /**
     * Adds a column to the database table
     *
     * Returns error object if fails. Returns false if table column already
     * exists. Returns true is successful.
     *
     * @param string  column    Name of column to add
     * @param string  parameter Specifics of table column
     * @param string  after     If supported, add column after this column
     * @param boolean indexed   Create an index on the column if true
     * @returns mixed
     */
    public function addTableColumn($column, $parameter, $after=null, $indexed=false)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::addTableColumn');
        }

        if (!DB::allowed($column)) {
            return Error::get(DB_BAD_COL_NAME, 'core', 'DB::addTableColumn', $column);
        }

        if ($this->isTableColumn($column)) {
            return false;
        }

        $sql = $GLOBALS['DB']['lib']->addColumn($table, $column, $parameter, $after);

        foreach ($sql as $val) {
            $result = DB::query($val);
            if (Error::isError($result)) {
                return $result;
            }
        }

        if ($indexed == true && DB_ALLOW_TABLE_INDEX) {
            $indexSql = "CREATE INDEX $column on $table($column)";
            $result = DB::query($indexSql);
            if (Error::isError($result)) {
                return $result;
            }
        }
        return true;
    }

    public function alterColumnType($column, $parameter)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::alterColumnType');
        }

        if (!DB::allowed($column)) {
            return Error::get(DB_BAD_COL_NAME, 'core', 'DB::alterColumnType', $column);
        }

        if (!$this->isTableColumn($column)) {
            return false;
        }

        $sql = $GLOBALS['DB']['lib']->alterTableColumn($table, $column, $parameter);
        $this->begin();
        foreach ($sql as $val) {
            $result = $this->query($val);
            if (Error::isError($result)) {
                $this->rollback();
                return $result;
            }
        }
        $this->commit();
        return true;
    }



    public function dropTableColumn($column)
    {
        $table = $this->getTable(false);
        if (!$table) {
            return Error::get(DB_ERROR_TABLE, 'core', 'DB::dropColumn');
        }

        if (!DB::allowed($column)) {
            return Error::get(DB_BAD_COL_NAME, 'core', 'DB::dropTableColumn', $column);
        }

        if ($this->isTableColumn($column)) {
            $sql = "ALTER TABLE $table DROP $column";
            return DB::query($sql);
        } else {
            return true;
        }
    }


    public static function getDBType()
    {
        return $GLOBALS['DB']['connection']->phptype;
    }


    public static function disconnect()
    {
        if (empty($GLOBALS['DB']['dbs'])) {
            return;
        }

        foreach ($GLOBALS['DB']['dbs'] as $db) {
            if (!empty($db['connection'])) {
                $db['connection']->disconnect();
            }
        }
        unset($GLOBALS['DB']);
    }

    /**
     * Imports a SQL dump file into the database.
     * This function can not be called statically.
     */

    public static function importFile($filename, $report_errors=true)
    {
        if (!is_file($filename)) {
            return Error::get(File_NOT_FOUND, 'core', 'DB::importFile');
        }
        $data = file_get_contents($filename);
        return DB::import($data, $report_errors);
    }

    /**
     * Imports a SQL dump into the database.
     * This function can not be called statically.
     * @returns True if successful, false if not successful and report_errors = false or
     *               Error object if report_errors = true
     */
    public static function import($text, $report_errors=true)
    {
        DB::touchDB();

        // first_import makes sure at least one query was completed
        // successfully
        $first_import = false;

        $sqlArray = Text::sentence($text);
        $error = false;

        foreach ($sqlArray as $sqlRow){
            if (empty($sqlRow) || preg_match("/^[^\w\d\s\\(\)]/i", $sqlRow)) {
                continue;
            }

            $sqlCommand[] = $sqlRow;

            if (preg_match("/;$/", $sqlRow)) {
                $query = implode(' ', $sqlCommand);
                $sqlCommand = array();

                if(!DB_ALLOW_TABLE_INDEX &&
                preg_match('/^create index/i', $query)) {
                    continue;
                }

                DB::homogenize($query);

                $result = DB::query($query);

                if (DB::isError($result)) {
                    if ($report_errors) {
                        return $result;
                    } else {
                        Error::log($result);
                        $error = true;
                    }
                }
                $first_import = true;
            }
        }

        if (!$first_import) {
            if ($report_errors) {
                return Error::get(DB_IMPORT_FAILED, 'core', 'DB::import');
            } else {
                Error::log(DB_IMPORT_FAILED, 'core', 'DB::import');
                $error = true;
            }
        }

        if ($error) {
            return false;
        } else {
            return true;
        }
    }


    public static function homogenize(&$query)
    {
        $query_list = explode(',', $query);

        $from[] = '/int\(\d+\)/iU';
        $to[]   = 'int';

        if (DB::getDBType() != 'mysql' &&
        DB::getDBType() != 'mysqli') {
            $from[] = '/mediumtext|longtext/i';
            $to[]   = 'text';
        }

        foreach ($query_list as $command) {
            // Remove mysql specific call
            $command = str_ireplace('unsigned', '', $command);
            $command = preg_replace('/ default (\'\'|""|``)/i', '', $command);

            if (preg_match ('/\s(smallint|int)\s/i', $command)) {
                if(!preg_match('/\snull/i', $command)) {
                    $command = str_ireplace(' int ', ' INT NOT NULL ', $command);
                    $command = str_ireplace(' smallint ', ' SMALLINT NOT NULL ', $command);
                }

                if(!preg_match('/\sdefault/i', $command)) {
                    $command = str_ireplace(' int ', ' INT DEFAULT 0 ', $command);
                    $command = str_ireplace(' smallint ', ' SMALLINT DEFAULT 0 ', $command);
                }

                $command = preg_replace('/ default \'(\d+)\'/Ui', ' DEFAULT \\1', $command);
            }



            $command = preg_replace($from, $to, $command);
            $newlist[] = $command;
        }

        $query = implode(',', $newlist);
        $GLOBALS['DB']['lib']->readyImport($query);
    }


    public function parsePearCol($info, $strip_name=false)
    {
        $setting = $GLOBALS['DB']['lib']->export($info);
        if (isset($info['flags'])) {
            if (stristr($info['flags'], 'multiple_key')) {
                if (DB_ALLOW_TABLE_INDEX) {
                    $column_info['index'] = 'CREATE INDEX ' .  $info['name'] . ' on ' . $info['table']
                    . '(' . $info['name'] . ');';
                }
                $info['flags'] = str_replace(' multiple_key', '', $info['flags']);
            }

            $preFlag = array('/not_null/i', '/primary_key/i', '/default_(\w+)?/i', '/blob/i', '/%3a%3asmallint/i', '/unique_key/');
            $postFlag = array('NOT NULL', '', "DEFAULT '\\1'", '', '', 'UNIQUE KEY');

            $flags = ' ' . preg_replace($preFlag, $postFlag, $info['flags']);
        } else {
            $flags = null;
        }


        if ($strip_name == true) {
            $column_info['parameters'] = $setting . $flags;
        }
        else {
            $column_info['parameters'] = $info['name'] . " $setting" . $flags;
        }

        return $column_info;
    }

    public function parseColumns($columns)
    {
        static $primary_keys = array();
        $table = $this->tables[0];
        foreach ($columns as $info){
            if (!is_array($info)) {
                continue;
            }

            if (stristr($info['flags'], 'primary_key')) {
                $primary_keys[$table][] = $info['name'];
            }


            $result = $this->parsePearCol($info);
            if (isset($result['index'])) {
                $column_info['index'][] = $result['index'];
            }

            $column_info['parameters'][] = $result['parameters'];
        }
        if (!empty($primary_keys[$table])) {
            $column_info['parameters'][] = sprintf('PRIMARY KEY (%s)', implode(',', $primary_keys[$table]));
        }

        return $column_info;
    }

    public function export($structure=true, $contents=true)
    {
        DB::touchDB();
        $table = $this->addPrefix($this->tables[0]);

        if ($structure == true) {
            $columns =  $GLOBALS['DB']['connection']->tableInfo($table);
            $column_info = $this->parseColumns($columns);
            $index = $this->getIndex();

            $sql[] = "CREATE TABLE $table ( " .  implode(', ', $column_info['parameters']) . ' );';
            if (isset($column_info['index'])) {
                $sql = array_merge($sql, $column_info['index']);
            }
        }

        if ($contents == true) {
            if ($rows = $this->select()) {
                if (Error::isError($rows)) {
                    return $rows;
                }
                foreach ($rows as $dataRow){
                    foreach ($dataRow as $key=>$value){
                        $allKeys[] = $key;
                        $allValues[] = DB::quote($value);
                    }

                    $sql[] = "INSERT INTO $table (" . implode(', ', $allKeys) . ') VALUES (' . implode(', ', $allValues) . ');';
                    $allKeys = $allValues = array();
                }
            }
        }

        if (!empty($sql)) {
            return implode("\n", $sql);
        } else {
            return null;
        }
    }

    public function quote($text)
    {
        return $GLOBALS['DB']['connection']->quote($text);
    }

    public static function extractTableName($sql_value)
    {
        $temp = explode(' ', trim($sql_value));

        if (!is_array($temp)) {
            return null;
        }
        foreach ($temp as $whatever){
            if (empty($whatever)) {
                continue;
            }
            $format[] = $whatever;
        }

        if (empty($format)) {
            return null;
        }

        switch (trim(strtolower($format[0]))) {
            case 'insert':
                if (stristr($format[1], 'into')) {
                    return preg_replace('/\(+.*$/', '', str_replace('`', '', $format[2]));
                } else {
                    return preg_replace('/\(+.*$/', '', str_replace('`', '', $format[1]));
                }
                break;

            case 'update':
                return preg_replace('/\(+.*$/', '', str_replace('`', '', $format[1]));
                break;

            case 'select':
            case 'show':
                return preg_replace('/\(+.*$/', '', str_replace('`', '', $format[3]));
                break;

            case 'drop':
            case 'alter':
                return preg_replace('/;/', '', str_replace('`', '', $format[2]));
                break;

            default:
                return preg_replace('/\W/', '', $format[2]);
                break;
        }
    }// END FUNC extractTableName


    /**
     * Prepares a value for database writing or reading
     *
     * @author Matt McNaney <matt at NOSPAM dot tux dot appstate dot edu>
     * @param  mixed $value The value to prepare for the database.
     * @return mixed $value The prepared value
     * @access public
     */
    public function dbReady($value=null)
    {
        if (is_array($value) || is_object($value)) {
            return DB::dbReady(serialize($value));
        } elseif (is_string($value)) {
            return "'" . $GLOBALS['DB']['connection']->escapeSimple($value) . "'";
        } elseif (is_null($value)) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return ($value ? 1 : 0);
        } else {
            return $value;
        }
    }// END FUNC dbReady()

    /**
     * Adds module title and class name to the load_class variable.
     * This list is called on the successful query of a loadObject or
     * getObjects. The list of files is erased as the files would not
     * need to be required again.
     */
    public function loadClass($module, $file)
    {
        $this->load_class[] = array($module, $file);
    }

    /**
     * Requires the classes, if any, in the load_class variable
     */
    public function requireClasses()
    {
        if ($this->load_class && is_array($this->load_class)) {
            foreach ($this->load_class as $files) {
                if (!is_array($files)) {
                    continue;
                }
                PHPWS_Core::initModClass($files[0], $files[1]);
            }
            $this->load_class = null;
        }
    }

    /**
     * @author Matt McNaney <mcnaney at gmail dot com>
     * @param  object $object        Object variable filled with result.
     * @param  object $require_where If true, require a where parameter or
     *                               have the id set
     * @return mixed                 Returns true if object properly populated and false otherwise
     *                               Returns error object if something goes wrong
     * @access public
     */
    public function loadObject($object, $require_where=true)
    {
        if (!is_object($object)) {
            return Error::get(DB_NOT_OBJECT, 'core', 'DB::loadObject');
        }

        if ($require_where && empty($object->id) && empty($this->where)) {
            return Error::get(DB_NO_ID, 'core', 'DB::loadObject', get_class($object));
        }

        if ($require_where && empty($this->where)) {
            $this->addWhere('id', $object->id);
        }

        $variables = $this->select('row');

        if (Error::isError($variables)) {
            return $variables;
        } elseif (empty($variables)) {
            return false;
        }

        return PHPWS_Core::plugObject($object, $variables);
    }// END FUNC loadObject

    /**
     * Creates an array of objects constructed from the submitted
     * class name.
     *
     * Use this function instead of select() to get an array of objects.
     * Note that your class variables and column names MUST match exactly.
     * Unmatched pairs will be ignored.
     *
     * --- Any extra parameters after class_name are piped into ---
     * --- a class method called postPlug. If the function    ---
     * --- does not exist, nothing happens. Previously, the     ---
     * --- the variables were put into the constructor.         ---
     * Example:
     * $db->getObjects('Class_Name', 'foo');
     * class Class_Name {
     * function postPlug($extra_param) {
     * } // end constuctor
     * } //end class
     *
     * @author Matthew McNaney <mcnaney at gmail dot com>
     * @param string $class_name Name of class used in object
     * @return array $items      Array of objects
     * @access public
     */
    public function getObjects($class_name)
    {
        $items = null;
        $result = $this->select();

        if (empty($result)) {
            return null;
        }

        if (Error::isError($result) || !isset($result)) {
            return $result;
        }

        $this->requireClasses();

        if (!class_exists($class_name)) {
            return Error::get(PHPWS_CLASS_NOT_EXIST, 'core', 'DB::getObjects', $class_name);
        }

        $num_args = func_num_args();
        if ($num_args > 1) {
            $args = func_get_args();
            array_shift($args);
        } else {
            $args = null;
        }

        foreach ($result as $indexby => $itemResult) {
            $genClass = new $class_name;

            if (isset($itemResult[0]) && is_array($itemResult[0])) {
                foreach ($itemResult as $key=>$sub) {
                    $genClass = new $class_name;
                    PHPWS_Core::plugObject($genClass, $sub, $args);
                    $items[$indexby][] = $genClass;
                }
            } else {
                PHPWS_Core::plugObject($genClass, $itemResult, $args);
                $items[$indexby] = $genClass;
            }

        }

        return $items;
    }

    public function saveObject($object, $stripChar=false, $autodetect_id=true)
    {
        if (!is_object($object)) {
            return Error::get(PHPWS_WRONG_TYPE, 'core', 'DB::saveObject', _('Type') . ': ' . gettype($object));
        }

        $object_vars = get_object_vars($object);

        if (!is_array($object_vars)) {
            return Error::get(DB_NO_OBJ_VARS, 'core', 'DB::saveObject');
        }

        foreach ($object_vars as $column => $value){
            if ($stripChar == true) {
                $column = substr($column, 1);
            }

            $isTblColumn = $this->isTableColumn($column);

            if(Error::isError($isTblColumn)){
                throw new Exception('Could not determine if column ' . $column . ' is a valid column in this table. Check table ownership.');
            }

            if (!$isTblColumn) {
                continue;
            }

            if ($autodetect_id && ($column == 'id' && $value > 0)) {
                $this->addWhere('id', $value);
            }

            $this->addValue($column, $value);
        }

        if (isset($this->qwhere) || !empty($this->where)) {
            $result = $this->update();
        } else {
            $result = $this->insert($autodetect_id);

            if (is_numeric($result)) {
                if (array_key_exists('id', $object_vars)) {
                    $object->id = (int)$result;
                } elseif (array_key_exists('_id', $object_vars)) {
                    $object->_id = (int)$result;
                }
            }
        }

        $this->resetValues();

        return $result;
    }


    public static function allowed($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $reserved = array('ADD', 'ALL', 'ALTER', 'ANALYZE', 'AND', 'AS', 'ASC', 'AUTO_INCREMENT', 'BDB',
                          'BERKELEYDB', 'BETWEEN', 'BIGINT', 'BINARY', 'BLOB', 'BOTH', 'BTREE', 'BY', 'CASCADE',
                          'CASE', 'CHANGE', 'CHAR', 'CHARACTER', 'COLLATE', 'COLUMN', 'COLUMNS', 'CONSTRAINT', 'CREATE',
                          'CROSS', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DATABASE', 'DATABASES', 'DATE',
                          'DAY_HOUR', 'DAY_MINUTE', 'DAY_SECOND', 'DEC', 'DECIMAL', 'DEFAULT',
                          'DELAYED', 'DELETE', 'DESC', 'DESCRIBE', 'DISTINCT', 'DISTINCTROW',
                          'DOUBLE', 'DROP', 'ELSE', 'ENCLOSED', 'ERRORS', 'ESCAPED', 'EXISTS', 'EXPLAIN', 'false', 'FIELDS',
                          'FLOAT', 'FOR', 'FOREIGN', 'FROM', 'FULLTEXT', 'FUNCTION', 'GEOMETRY', 'GRANT', 'GROUP',
                          'HASH', 'HAVING', 'HELP', 'HIGH_PRIORITY', 'HOUR_MINUTE', 'HOUR_SECOND',
                          'IF', 'IGNORE', 'IN', 'INDEX', 'INFILE', 'INNER', 'INNODB', 'INSERT', 'INT',
                          'INTEGER', 'INTERVAL', 'INTO', 'IS', 'JOIN', 'KEY', 'KEYS', 'KILL', 'LEADING',
                          'LEFT', 'LIKE', 'LIMIT', 'LINES', 'LOAD', 'LOCK', 'LONG', 'LONGBLOB', 'LONGTEXT',
                          'LOW_PRIORITY', 'MASTER_SERVER_ID', 'MATCH', 'MEDIUMBLOB', 'MEDIUMINT', 'MEDIUMTEXT',
                          'MIDDLEINT', 'MINUTE_SECOND', 'MRG_MYISAM', 'NATURAL', 'NOT', 'NULL', 'NUMERIC', 'ON', 'OPTIMIZE',
                          'OPTION', 'OPTIONALLY', 'OR', 'ORDER', 'OUTER', 'OUTFILE', 'PRECISION', 'PRIMARY', 'PRIVILEGES',
                          'PROCEDURE', 'PURGE', 'READ', 'REAL', 'REFERENCES', 'REGEXP', 'RELEASE', 'RENAME', 'REPLACE', 'REQUIRE',
                          'RESTRICT', 'RETURNS', 'REVOKE', 'RIGHT', 'RLIKE', 'RTREE', 'SELECT', 'SET', 'SHOW',
                          'SMALLINT', 'SONAME', 'SPATIAL', 'SQL_BIG_RESULT', 'SQL_CALC_FOUND_ROWS', 'SQL_SMALL_RESULT',
                          'SSL', 'STARTING', 'STRAIGHT_JOIN', 'STRIPED', 'TABLE', 'TABLES', 'TERMINATED', 'THEN', 'TINYBLOB',
                          'TINYINT', 'TINYTEXT', 'TO', 'TRAILING', 'true', 'TYPES', 'UNION', 'UNIQUE', 'UNLOCK', 'UNSIGNED',
                          'UPDATE', 'USAGE', 'USE', 'USER_RESOURCES', 'USING', 'VALUES', 'VARBINARY', 'VARCHAR', 'VARYING',
                          'WARNINGS', 'WHEN', 'WHERE', 'WITH', 'WRITE', 'XOR', 'YEAR_MONTH', 'ZEROFILL');

        if(in_array(strtoupper($value), $reserved)) {
            return false;
        }

        if(preg_match('/[^\w\*\.]/', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Crutch function from old database
     */
    public function sqlFriendlyName($name) {
        if (!DB::allowed($name)) {
            return false;
        }

        return preg_replace('/\W/', '', $name);
    }

    public function updateSequenceTable()
    {
        $this->addColumn('id', 'max');

        $max_id = $this->select('one');

        if (Error::isError($max_id)) {
            return $max_id;
        }

        if ($max_id > 0) {
            $seq_table = $this->getTable(false) . '_seq';
            if (!$this->isTable($seq_table)) {
                $table = $this->addPrefix($this->getTable(false));
                $GLOBALS['DB']['connection']->nextId($table);
            }

            $seq = new DB($seq_table);
            $result = $seq->select('one');
            if (Error::logIfError($result)) {
                return false;
            }

            $seq->addValue('id', $max_id);
            if (!$result) {
                return $seq->insert(false);
            } else {
                return $seq->update();
            }
        }

        return true;
    }

    public static function addPrefix($table)
    {
        if (isset($GLOBALS['DB']['tbl_prefix'])) {
            return $GLOBALS['DB']['tbl_prefix'] . $table;
        }
        return $table;
    }

    public static function getPrefix()
    {
        if (isset($GLOBALS['DB']['tbl_prefix'])) {
            return $GLOBALS['DB']['tbl_prefix'];
        }
        return null;
    }


    /**
     * @author Matthew McNaney
     * @author Hilmar
     */
    public static function prefixQuery($sql)
    {
        if (!$GLOBALS['DB']['tbl_prefix']) {
            return $sql;
        }
        $tables = DB::pullTables($sql);

        if (empty($tables)) {
            return $sql;
        }

        foreach ($tables as $tbl) {
            $tbl = trim($tbl);
            $sql = DB::prefixVary($sql, $tbl);
        }
        return $sql;
    }

    /**
     * Prefix tablenames, but not within 'quoted values', called from prefixQuery
     * @author Hilmar
     */
    public static function prefixVary($sql, $tbl)
    {
        $repl = true;
        $ar = explode("'", $sql);

        foreach ($ar as $v) {
            if ($repl) {
                $subsql[] = preg_replace("/([\s\W])$tbl(\W)|([\s\W])$tbl$/",
                                         '$1${3}' . $GLOBALS['DB']['tbl_prefix'] . $tbl . '$2', $v);
                $repl = false;
            } else {
                $subsql[] = $v;
                if (substr($v, -1, 1) == "\\") continue;
                $repl = true;
            }
        }
        $sql = implode('\'', $subsql);

        return $sql;
    }

    public static function pullTables($sql)
    {
        $sql = preg_replace('/ {2,}/', ' ', trim($sql));
        $sql = preg_replace('/[\n\r]/', ' ', $sql);
        $command = substr($sql, 0,strpos($sql, ' '));

        switch(strtolower($command)) {
            case 'alter':
                if (!preg_match('/alter table/i', $sql)) {
                    return false;
                }
                $aQuery = explode(' ', preg_replace('/[^\w\s]/', '', $sql));
                $tables[] = $aQuery[2];
                break;

            case 'create':
                if (preg_match('/^create (unique )?index/i', $sql)) {
                    $start = stripos($sql, ' on ') + 4;
                    $para = stripos($sql, '(');
                    $length = $para - $start;
                    $table =  substr($sql, $start, $length);
                } else {
                    $aTable = explode(' ', $sql);
                    $table = $aTable[2];
                }
                $tables[] = trim(preg_replace('/\W/', '', $table));
                break;

            case 'delete':
                $start = stripos($sql, 'from') + 4;
                $end = strlen($sql) - $start;
                $table = substr($sql, $start, $end);
                $table = preg_replace('/where.*/i', '', $table);
                if (preg_match('/using/i', $table)) {
                    $table = preg_replace('/[^\w\s,]/', '', $table);
                    $table = preg_replace('/\w+ using/iU', '', $table);
                    return explode(',', preg_replace('/[^\w,]/', '', $table));
                }
                $tables[] = preg_replace('/\W/', '', $table);
                break;

            case 'drop':
                $start = stripos($sql, 'on') + 2;
                $length = strlen($sql) - $start;
                if (preg_match('/^drop index/i', $sql)) {
                    $table = substr($sql, $start, $length);
                    $tables[] = preg_replace('/[^\w,]/', '', $table);
                } else {
                    $table = preg_replace('/drop |table |if exists/i', '', $sql);
                    return explode(',', preg_replace('/[^\w,]/', '', $table));
                }
                break;

            case 'insert':
                $table =  preg_replace('/insert |into | values|\(.*\)/i', '', $sql);
                $tables[] = preg_replace('/\W/', '', $table);
                break;

            case 'select':
                $start = stripos($sql, 'from') + 4;
                $table = substr($sql, $start, strlen($sql) - $start);

                if ($where = stripos($table, ' where ')) {
                    $table = substr($table, 0, $where);
                }

                if ($order = stripos($table, ' order by')) {
                    $table = substr($table, 0, $order);
                }

                if ($group = stripos($table, ' group by')) {
                    $table = substr($table, 0, $group);
                }

                if ($having = stripos($table, ' having ')) {
                    $table = substr($table, 0, $having);
                }

                if ($limit = stripos($table, ' limit ')) {
                    $table = substr($table, 0, $limit);
                }

                $table = str_ireplace(' join ', ' ', $table);
                $table = str_ireplace(' right ', ' ', $table);
                $table = str_ireplace(' left ', ' ', $table);
                $table = str_ireplace(' inner ', ' ', $table);
                $table = str_ireplace(' outer ', ' ', $table);
                $table = str_ireplace(' on ', ' ', $table);
                $table = str_ireplace(' and ', ' ', $table);
                $table = str_ireplace(' or ', ' ', $table);
                $table = str_ireplace(' not ', ' ', $table);
                $table = str_ireplace('=', ' ', $table);
                $table = str_ireplace(',', ' ', $table);
                $table = preg_replace('/\w+\.\w+/', ' ', $table);
                $table = preg_replace('/(as \w+)/i', '', $table);
                $table = preg_replace('/ \d+$| \d+ /', ' ', $table);
                $table = preg_replace('/\'.*\'/', ' ', trim($table));
                $table = preg_replace('/ {2,}/', ' ', trim($table));
                $tables = explode(' ', $table);

                return $tables;
                break;

            case 'update':
                $aTable = explode(' ', $sql);
                $tables[] = preg_replace('/\W/', '', $aTable[1]);
                break;

            case 'lock':
                $sql = preg_replace('/lock tables/i', '', $sql);
                $aTable = explode(',', $sql);

                foreach ($aTable as $tbl) {
                    $tables[] = substr($tbl, 0, strpos(trim($tbl) + 1, ' '));
                }
                break;
        }

        return $tables;
    }

    public function setLock($table, $status='write')
    {
        if (!is_string($table) || !is_string($status)) {
            return false;
        }

        $status = strtolower($status);

        if ($status != 'read' && $status != 'write') {
            return false;
        }

        if (in_array($table, $this->tables)) {
            $this->locked[] = array('table' => $table,
                                    'status' => $status);
        }
    }

    public function lockTables()
    {
        if (!ALLOW_TABLE_LOCKS) {
            return true;
        }

        if (empty($this->locked)) {
            return false;
        }

        $query = $GLOBALS['DB']['lib']->lockTables($this->locked);
        return $this->query($query);
    }

    public function unlockTables()
    {
        if (!ALLOW_TABLE_LOCKS) {
            return true;
        }

        $query = $GLOBALS['DB']['lib']->unlockTables();
        return $this->query($query);
    }


    public function begin()
    {
        // If transaction started already, return false.
        if (isset($GLOBALS['DB_Transaction']) && $GLOBALS['DB_Transaction']) {
            return false;
        }
        $GLOBALS['DB_Transaction'] = true;
        return DB::query('BEGIN');
    }

    public function commit()
    {
        // if transaction not started, return false.
        if (!$GLOBALS['DB_Transaction']) {
            return false;
        }
        $GLOBALS['DB_Transaction'] = false;
        return DB::query('COMMIT');
    }

    public function rollback()
    {
        // if transaction not started, return false.
        if (!$GLOBALS['DB_Transaction']) {
            return false;
        }
        $GLOBALS['DB_Transaction'] = false;
        return DB::query('ROLLBACK');
    }

    /**
     * Move row in a table based on a column designating the current order
     * direction == 1 means INCREASE order by one
     * direction == -1 means DECREASE order by one
     * @param string  order_column Table column that contains the order of the entries
     * @param string  id_column    Name of the id_column
     * @param integer id           Id of current row
     * @param integer direction    Direction to move the row
     */
    public function moveRow($order_column, $id_column, $id, $direction=1)
    {
        if ( !($direction == 1 || $direction == -1) ) {
            if (strtolower($direction) == 'down') {
                $direction = 1;
            } elseif (strtolower($direction) == 'up') {
                $direction = -1;
            } else {
                return false;
            }
        }

        $total_rows = $this->count();
        if ($total_rows < 2) {
            return;
        }

        $db = clone($this);
        $db->reset();
        $db->addWhere($id_column, $id);
        $db->addColumn($order_column);
        $current_order = $db->select('one');

        if ($current_order == 1 && $direction == -1) {
            // moving up when current item is at top of list
            // need to shift all other items down and pop this on the end
            DB::begin();

            if (Error::logIfError($this->reduceColumn($order_column))) {
                DB::rollback();
                return false;
            }
            $db->reset();
            $db->addWhere($id_column, $id);
            $db->addValue($order_column, $total_rows);
            if (Error::logIfError($db->update())) {
                DB::rollback();
                return false;
            }
            DB::commit();
            unset($db);
            return true;
        } elseif ($current_order == $total_rows && $direction == 1) {
            // moving down when current item is at bottom/end of list
            // need to shift all other items up and shift this on the beginning
            DB::begin();
            if (Error::logIfError($this->incrementColumn($order_column))) {
                DB::rollback();
                return false;
            }
            $db->reset();
            $db->addWhere($id_column, $id);
            $db->addValue($order_column, 1);
            if (Error::logIfError($db->update())) {
                DB::rollback();
                return false;
            }
            DB::commit();
            unset($db);
            return true;
        } else {
            DB::begin();
            $db = clone($this);
            $db->addWhere($order_column, $current_order + $direction);
            $db->addValue($order_column, $current_order);
            if (Error::logIfError($db->update())) {
                DB::rollback();
                return false;
            }

            $db = clone($this);
            $db->addWhere($id_column, $id);
            $db->addValue($order_column, $current_order + $direction);
            if (Error::logIfError($db->update())) {
                DB::rollback();
                return false;
            }
            unset($db);
            return true;
        }
    }

    public function setSubselectAs($ssa)
    {
        if ($this->allowed($ssa)) {
            $this->subselect_as = $ssa;
        }
    }
}

class DB_Where {
    public $table      = null;
    public $column     = null;
    public $value      = null;
    public $operator   = '=';
    public $conj       = 'AND';
    public $join       = false;

    public function setJoinTable($table)
    {
        $this->join_table = $table;
    }

    public function setColumn($column)
    {
        $this->column = $column;
    }

    /**
     * Set operator after checking for compatibility
     * addWhere strtouppers the operator
     */
    public function setOperator($operator)
    {
        if (empty($operator)) {
            return false;
        }

        if (!DB::checkOperator($operator)) {
            return Error::get(DB_BAD_OP, 'core', 'DB::addWhere', _('DB Operator:') . $operator);
        }

        if ($operator == 'LIKE' || $operator == 'ILIKE') {
            $operator = $GLOBALS['DB']['lib']->getLike();
        } elseif ($operator == 'NOT LIKE' || $operator == 'NOT ILIKE') {
            $operator = 'NOT ' . $GLOBALS['DB']['lib']->getLike();
        } elseif ($operator == '~' || $operator == '~*' || $operator == 'REGEXP' || $operator == 'RLIKE') {
            $operator = $GLOBALS['DB']['lib']->getRegexp();
        } elseif ($operator == '!~' || $operator == '!~*' || $operator == 'NOT REGEXP' || $operator == 'NOT RLIKE') {
            $operator = $GLOBALS['DB']['lib']->getNotRegexp();
        }

        $this->operator = $operator;
    }

    public function setJoin($join)
    {
        $this->join = (bool)$join;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function setTable($table)
    {
        $this->table = $table;
    }


    public function setConj($conj)
    {
        $conj = strtoupper($conj);
        if (empty($conj) || ($conj != 'OR' &&  $conj != 'AND')) {
            return false;
        }

        $this->conj = $conj;
    }

    public function getValue()
    {
        $value = $this->value;

        if (is_array($value)) {
            switch ($this->operator){
                case 'IN':
                case 'NOT IN':
                    foreach ($value as $temp_val) {
                        if ($temp_val != 'NULL') {
                            $temp_val_list[] = "'$temp_val'";
                        } else {
                            $temp_val_list[] = $temp_val;
                        }
                    }
                    $value = '(' . implode(', ', $temp_val_list) . ')';

                    break;

                case 'BETWEEN':
                case 'NOT BETWEEN':
                    $value = sprintf("'{%s}' AND '{%s}'", $this->value[0], $this->value[1]);
                    break;
            }
            return $value;
        }

        // If this is not a joined where, return the escaped value
        if (!$this->join && $value != 'NULL') {
            return sprintf('\'%s\'', $value);
        } else {
            // This is a joined value, return table.value
            return $value;
        }

    }

    public function get()
    {
        $column = $this->table . '.' . $this->column;
        $value = $this->getValue();
        $operator = &$this->operator;
        return sprintf('%s %s %s', $column, $operator, $value);
    }
} // END DB_Where

?>