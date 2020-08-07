<?php

namespace Qiblat\Crud\Model;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Crud
{

    // DB Class
    protected $db = null;
    protected $_tableName = null;
    protected $_connectionName = null;

    // Main Model untuk ambil tableName, pk, etc (bentuk ::class)
    protected $baseModel = null;

    // Main Model yang sudah berbentuk Class Created, bisa dipake ->...
    protected $baseClass = null;

    // List field untuk tabel ini
    protected $fieldList = [];
    protected $arrFieldList = [];
    protected $hiddenFieldList = [];

    // List join
    protected $joinList = [];

    // List relation yang masih berbentuk ::class
    protected $_withRelation = [];

    // List Relation lengkap dengan pk, fk, relation_name, etc
    protected $relationList = [];

    // Key yang bisa di SearchPrimary
    protected $_searchablePrimary = [];

    // Hasil searchablePrimary
    protected $_searchableResult = null;


    protected $_keyBy = null;
    protected $_pluck = null;
    protected $_isCaching = false;
    protected $_cachingDuration = 0;
    protected $_whereIn = [];
    protected $_like = [];

    protected $_limit = null;
    protected $_where = null;
    protected $_select = [];
    protected $_order = null;
    protected $_isMapping = null;
    protected $_mappingFunction = null;

    protected $_toArray = false;
    protected $_enablePaging = false;
    protected $_page = 1;
    protected $_perPage = 10;

    protected $_paginationMeta = [];

    protected $tableRelation = [];

    public function __construct()
    {
        global $db;

        $this->db = $db;

        /**
         *
         * tableName => [
         * 	child_name => [pk => 'pk_child', 'fk' => 'pk_child_di_parent']
         * ]
         */
        $this->tableRelation = Config::get('crud.TABLE_RELATION');
    }



    protected $hiddenField = [
        'user' => ['password', 'token', 'pin', 'pin_password'],
    ];


    // Pake Relation
    public function with($relation)
    {
        if (is_array($relation)) {
            $this->_withRelation = $relation;
        } else {
            $this->_withRelation[] = $relation;
        }

        foreach ($this->_withRelation as $k => $v) {
            if (is_numeric($k)) {
                $this->relationList[$v] = $this->_getRelationKey($this->baseClass, $v);
            } else {
                $this->relationList[$k] = $this->_getRelationKey($this->baseClass, $k);
            }
        }

        return $this;
    }

    public function paging()
    {
        $this->_perPage = $this->config->item('per_page') != null ? $this->config->item('per_page') : 15;
        $this->_page = $this->config->item('page');
        $this->_enablePaging = TRUE;
        return $this;
    }


    public function keyBy($key)
    {
        $this->_keyBy = $key;
        return $this;
    }
    public function orderBy($field, $direction)
    {
        if (!Str::contains($field, '.')) {
            $field = $this->_tableName . "." . $field;
        }
        $this->_order = [$field, $direction];

        $this->db->orderBy($field, $direction);
        return $this;
    }
    public function join($keys, $condition = null, $parentClass = null)
    {

        $baseClass = $parentClass ? $parentClass : $this->baseClass;
        $parentTableName = $parentClass ? $parentClass::getTableName() : $this->_tableName;
        // TODO: HIDE RELATION FIELD
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $key => $val) {
            if (!is_numeric($key)) {
                $this->join($key);
                $this->join($val, null, $key);
            } else if (isModel($val)) {

                $joinModel = (new $val);
                $joinTableName = $joinModel->getTableName();
                $hiddenField = $joinModel->getHiddenField();
                $this->joinList[] = [
                    'tableName' => $joinModel->getTableName(),
                    'fieldList' => $joinModel->getTableColumns(),
                    'hiddenField' => $hiddenField,
                ];
                if ($condition == null) {
                    $relation = $this->tableRelation[$baseClass][$val];
                    $relation = $this->_getRelationKey($baseClass, $val);

                    $relationConName = $relation['connectionName'];
                    $relationDbName = config("database.connections.$relationConName.database");

                    $parentConName = $baseClass::getConName();
                    $parentDbName = config("database.connections.$parentConName.database");

                    $parentDbName = $parentDbName . ".dbo.";
                    $relationDbName = $relationDbName . ".dbo.";

                    $relation['relationTable'] = $relationDbName . $relation['relationTable'];
                    $this->db->leftJoin($relation['relationTable'], $relation['relationTable'] . "." . $relation['pk'], '=', $parentDbName . $parentTableName . "." . $relation['fk']);


                    $selects = $relation['relationModel']->getTableColumns();
                    $fields = [];
                    $arrFields = [];
                    $hidFields = [];

                    foreach ($selects as $k => $v) {
                        $arrFields[$relation['relationName'] . "-$v"] = $relation['relationTable'] . ".$v";
                        $selects[$k] = $relation['relationTable'] . ".$v as " . $relation['relationName'] . "-$v";
                        $fields[] = $relation['relationName'] . "-$v";

                        if (in_array($v, $hiddenField)) {
                            $hidFields[] = $relation['relationName'] . "-$v";
                        }
                    }

                    $this->arrFieldList = array_merge($this->arrFieldList, $arrFields);
                    $this->_select = array_merge($this->_select, $selects);
                    $this->fieldList = array_merge($this->fieldList, $fields);
                    $this->hiddenFieldList = array_merge($this->hiddenFieldList, $hidFields);
                }
            }
        }
        return $this;
    }
    public function like($field, $condition)
    {
        if (!Str::contains($field, '.'))   $field = $this->_tableName . "." . $field;
        $this->db->where($field, 'like', '%' . $condition . '%');
        return $this;
    }
    public function caching($duration = 3600)
    {
        $this->_isCaching = TRUE;
        $this->_cachingDuration = $duration;
        return $this;
    }
    public function pluck($key)
    {
        $this->_pluck = $key;
        return $this;
    }
    public function limit($count)
    {
        $this->_limit = $count;
        $this->db->limit($count);
        return $this;
    }
    public function where($condition, $rule = null, $extras = null)
    {
        if ($rule) {
            $this->_where = [$condition => $rule];
            $this->db->where($condition, $rule, $extras);
        } else {
            $this->_where = $condition;
            $this->db->where($condition);
        }
        return $this;
    }

    // $relationClass = class / table
    public function whereHas($relationClass, $extra = null)
    {
        $this->db->whereExists(function ($query) use ($relationClass, $extra) {
            $relationKey = $this->_getRelationKey($this->baseClass, $relationClass);
            $relationFk = $relationKey['fk'];
            $relationPk = $relationKey['pk'];
            $relationTable = $relationKey['relationTable'];
            $query->select(DB::raw(1))
                ->from($relationTable)
                ->whereRaw("$this->_tableName.$relationFk = $relationTable.$relationPk");
            if ($extra) {
                $query->where($extra);
            }
        });
        return $this;
    }

    // Set Unique searchable Field
    public function searchPrimary($request, $searchableField = [])
    {
        if ($request->get('find')) {
            if (!is_array($request->get('find'))) {
                return $this;
            }
            $filtered = array_intersect_key($request->get('find'), array_flip($searchableField));
            if (sizeof($filtered) > 0) {
                foreach ($filtered as $k => $v) {
                    $this->db->where("$this->_tableName.$k", $v);
                }
                // $this->_searchableResult = $this->db->firstOrFail();
                $this->_searchableResult = $this->_runQuery('firstOrFail');
                if (sizeof($this->_withRelation)) {
                    $this->_searchableResult = $this->hasOne($this->_searchableResult, $this->_withRelation, $this->_isCaching);
                }
            }
        }

        $this->_searchablePrimary = array_merge($this->_searchablePrimary, $searchableField);
        return $this;
    }


    public function mergeFieldList($field)
    {
        $this->fieldList = array_merge($this->fieldList, $field);
    }

    public function mergeSelect($select)
    {
        $this->_select = array_merge($this->_select, $select);
    }

    public function select($select)
    {
        $this->_select = $select;
        $this->db->select($select);
        return $this;
    }

    public function whereIn($key, $value)
    {
        $this->_whereIn = [$key, $value];
        if (!$value) {
            $this->db->where($key, null);
        } else {
            $this->db->whereIn($key, $value);
        }
        return $this;
    }

    public function toArray()
    {
        $this->_toArray = true;
        return $this;
    }

    private function _softDelete($where)
    {
        if (array_key_exists('deleted_at', $where)) {
            // return $where;
        } else {
            $this->db->whereNull($this->_tableName . '.deleted_at');
        }
    }


    private function _initQuery($table = '', $condition = [])
    {
        $this->init($table);
        $this->_softDelete($condition);

        if (sizeof($this->_like) > 0) {
            $this->db->where($this->_like[0], 'like', '%' . $this->_like[1] . '%');
        }

        $this->db->where($condition);
    }

    public function clean_cache($table)
    {
        $table = getTableName($table);
    }


    public function _execute($function, $extras = null)
    {

        $paginationMeta = null;
        if ($function === 'exists') {
            $result = $this->db->exists();
        } else if ($function === 'get') {
            $result = $this->db->get()->toArray();
        } else if ($function === 'first') {
            $result = $this->db->first();
        } else if ($function === 'firstOrFail') {
            $result = $this->db->firstOrFail();
        } else if ($function == 'pagination') {
            // de($this->db->toSql());
            $paginateResult = $this->db->paginate($extras['perPage'], ['*'], '', $extras['page']);

            $paginationMeta = [
                'current_page' => $paginateResult->currentPage(),
                'last_page' => $paginateResult->lastPage(),
                'per_page' => $paginateResult->perPage(),
                'current_item' => $paginateResult->count(),
                'total' => $paginateResult->total(),
            ];
            $result = $paginateResult->items();
        } else {
            $result = $this->db->count();
        }
        return ['result' => $result, 'paginationMeta' => $paginationMeta];
    }

    private function _runQuery($function = '', $extras = null)
    {

        $query = Str::replaceArray('?', $this->db->getBindings(), $this->db->toSql()) . "_" . ($extras ? implode("|", $extras) : '');
        $encryptQuery = sha1($query);
        $cacheName = $this->_tableName . "_" . $encryptQuery;
        if ($this->_isCaching) {
            $resultCache = Cache::remember($cacheName, $this->_cachingDuration, function () use ($function, $extras) {
                return $this->_execute($function, $extras);
            });
        } else {
            $resultCache = $this->_execute($function, $extras);
        }

        if ($resultCache['paginationMeta']) {
            $this->_paginationMeta = $resultCache['paginationMeta'];
        }

        return $resultCache['result'];
    }


    public function init($tableName, $isPrimary = true, $er = '', $bool = '')
    {
        if ($this->db == null) {
            if (isModel($tableName)) {
                $this->baseClass = $tableName;
                $this->baseModel = new $tableName;
                $tableName = $this->baseModel->getTableName();
                $this->_connectionName = $this->baseModel->getConName();
            }
            $this->_tableName = $tableName;
            $db = DB::connection($this->_connectionName);
            $this->db = $db;
            $this->db = $this->db->table($tableName);

            $columns = $this->baseModel->getTableColumns();

            $arrFieldList = [];
            foreach ($columns as $v) {
                $arrFieldList[$v] = $this->_tableName . "." . $v;
            }
            $this->arrFieldList = array_merge($this->arrFieldList, $arrFieldList);

            $this->mergeFieldList($columns);
            foreach ($columns as $k => $v) {
                $columns[$k] = "$tableName.$v";
            }

            $this->mergeSelect($columns);
            $this->hiddenFieldList = $this->baseModel->getHiddenField();
        } else { }
        return $this;
    }

    // Cek apakah data ada atau tidak, return true/false
    public function isExists($table = '', $condition = [])
    {
        $this->_initQuery($table, $condition, 1);
        $this->_reset_var();
        $exists = $this->_runQuery('exists');
        if ($exists)  return true;
        return false;
    }


    // Ambil total data yang ada
    public function getCount($table, $condition = [])
    {
        $this->_initQuery($table, $condition);
        $this->_reset_var();
        return $this->_runQuery('count');
    }


    // Ambil data pertama - first row
    public function first($table = null, $where = [], $select = null, $order = null)
    {
        $this->_initQuery($table);
        $row = $this->_runQuery('first');
        if (sizeof($this->_withRelation)) {
            $result = $this->hasOne($row, $this->_withRelation, $this->_isCaching);
        }

        $this->_reset_var();

        if ($this->_isMapping) {
            $result = $this->loopFunction([$result])[0];
        }
        return $row;
    }

    public function map($function)
    {
        $this->_isMapping = TRUE;
        $this->_mappingFunction = $function;
        $function = $this->_mappingFunction;
        return $this;
    }

    private function loopFunction($data)
    {
        $function =  $this->_mappingFunction;
        foreach ($data as $k => $v) {
            $data[$k] = $function($v);
        }
        return $data;
    }

    public function paginate($perPage = 10, $select = null, $page = 1, $sort = null)
    {
        $this->_enablePaging = TRUE;
        if (is_array($sort)) {
            $sort['field'] = str_replace("-", ".", $sort['field']);
            $sort = [$sort['field'], $sort['sort']];
        }

        $data = $this->get(null, [], $sort, $select, null, null, ['perPage' => $perPage, 'page' => $page]);


        $data = $this->_normalizeHiddenField($data);

        $result = array_merge($this->_paginationMeta, ['data' => $data]);
        return $result;
    }


    // Function for METRONIC ktTable API
    public function ktTable($request)
    {
        $data = $this->autoPaginate($request, ['all']);
        $result['data'] = $data['data'];
        unset($data['data']);
        $data['field'] = Str::contains($this->_order[0], '.') ? explode(".", $this->_order[0])[1] : $this->_order[0];
        $data['sort'] = Str::contains($this->_order[1], '.') ? explode(".", $this->_order[1])[1] : $this->_order[1];
        $data['perpage'] = $data['per_page'];
        $data['pages'] = $data['last_page'];
        $data['page'] = $data['current_page'];

        unset($data['current_page']);
        unset($data['per_page']);
        unset($data['last_page']);

        $result['meta'] = $data;


        $hiddenField = $this->baseModel->getHiddenFIeld();
        foreach ($result['data'] as $k => $v) {
            foreach ($hiddenField as $ke => $ve) {
                if (isset($v->{$ve})) {
                    unset($result['data'][$k]->{$ve});
                }
            }
        }

        return $result;
    }

    public function autoPaginate($request, $allowedSearch = [])
    {

        if ($this->_searchableResult) {
            if ($this->_isMapping) {
                $this->_searchableResult = $this->loopFunction([$this->_searchableResult])[0];
            }

            $this->_searchableResult = $this->_normalizeHiddenField([$this->_searchableResult])[0];
            return $this->_searchableResult;
        }

        $this->db->select($this->_select);

        // Default page dan per_page
        $perPage = @$request->get('pagination')['perpage'] ?: 10;
        $page = @$request->get('pagination')['page'] ?: 1;

        // Determine Sorting Field by Available Field
        $sort['field'] = 'created_at';
        $sort['sort'] = 'DESC';
        if (is_array($request->get('sort'))) {
            if (sizeof($request->get('sort')) == 2) {
                $sort['field'] =  $request->get('sort')['field'];
                $sort['sort'] = $request->get('sort')['sort'];
            }
        }

        // Validating Input
        $inputValue  = [
            'per_page' => $perPage,
            'page'     => $page,
            'sort'     => $sort,
            'query'   => $request->get('query')
        ];
        $inputValidator = [
            'per_page'      => ['numeric'],
            'page'          => ['numeric'],
            'sort.field'    => [Rule::in($this->fieldList)],
            'sort.sort'     => [Rule::in(['DESC', 'desc', 'ASC', 'asc'])],
        ];


        $validator = Validator::make($inputValue, $inputValidator);
        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        if (is_array($request->get('query'))) {
            // Ambil key yang di allow saja dari param 'query' array
            if (in_array('all', $allowedSearch)) {
                // Jika allow semua search
                $allowedSearch = array_merge($this->fieldList, ['general'], $allowedSearch);
            }


            $filter = array_intersect_key($request->get('query'), array_flip($allowedSearch));
            foreach ($filter as $k => $v) {
                if ($k != 'general') {
                    if (Str::contains($k, '.')) {
                        $search = explode(".", $k);
                        $relationData = arraySerach($this->relationList, "relationTable", $search[0]);
                        $this->whereHas($relationData['relationClass'], function ($query) use ($v, $search) {
                            if (sizeof($search) > 2) {

                                // TODO: TESTING !!!
                                $query->whereExists(function ($query) use ($v, $search) {
                                    $relationTableSingular = substr($search[1], 0, -1) . "_id";
                                    $query->select(DB::raw(1))
                                        ->from($search[1])
                                        ->whereRaw("$search[1].id = $search[0].$relationTableSingular")->where($search[2], $v);
                                });
                            } else {
                                if (is_array($v)) {
                                    $query->whereIn($search[1], $v);
                                } else {
                                    $query->where($search[1], $v);
                                }
                            }
                        });
                    } else {
                        $field = $this->arrFieldList[$k];
                        if (is_array($v)) {
                            $this->db->whereIn($field, $v);
                        } else {
                            $this->db->where($field, $v);
                        }
                    }
                } else {

                    // // General Search. Grouping dulu, lalu where like semua field yang ada
                    $this->db->where(function ($query) use ($v) {
                        foreach (($this->arrFieldList) as $ve) {
                            $query->orWhere($ve, 'like', '%' . $v . '%');
                        }
                    });
                }
            }
        }

        $result = $this->paginate($perPage, null, $page, $sort);

        return $result;
    }

    public function _normalizeHiddenField($data, $baseClass = null, $objName = null)
    {
        if (is_array($data)) {
            $hiddenField = $baseClass ? $baseClass::getHiddenField() : $this->baseClass::getHiddenField();
            foreach ($data as $k => $v) {
                // foreach ($hiddenField as $ke => $ve) {
                //     if (property_exists($v, $ve)) {
                //         unset($data[$k]->{$ve});
                //     }
                // }

                $data[$k] = (array_diff_key((array) $data[$k], array_flip($this->hiddenFieldList)));
            }
        }

        if (sizeof($this->_withRelation)) {
            $hiddenObj = $this->_normalizeHiddenFieldRelation($this->baseClass, $this->_withRelation)['subClass'];
            $hiddenRes = [];
            $hiddenRes = $this->_normalizeHiddenFieldName($hiddenObj, $hiddenRes);

            $data = $this->_removeHiddenField($data, $hiddenRes, null);
        }

        return $data;
    }

    private function _removeHiddenField($data, $hiddenField, $isDebug = false)
    {

        $isObj = false;
        if (is_object($data)) {
            $isObj = true;
            $data = [$data];
        }
        foreach ($data as $k => $v) {
            foreach ($hiddenField as $ke => $ve) {
                if (is_array($ve)) {
                    if (is_array($v)) {
                        if (isset($v[$ke])) {
                            $data[$k][$ke] = $this->_removeHiddenField($v[$ke], $ve, $isDebug);
                        }
                    } else if (is_object($v)) {
                        if (isset($v->{$ke})) {
                            $data[$k]->{$ke} = $this->_removeHiddenField($v->{$ke}, $ve, $isDebug);
                        }
                    } else { }
                } else {
                    if (is_array($data[$k])) {
                        unset($data[$k][$ve]);
                    } else {
                        unset($data[$k]->{$ve});
                    }
                }
            }
        }

        if ($isObj) $data = $data[0];

        return $data;
    }

    private function _normalizeHiddenFieldName($hiddenObj, $data)
    {
        foreach ($hiddenObj as $k => $v) {
            if (isset($this->relationList[$v['class']])) {
                $relationData = $this->relationList[$v['class']];
            } else {
                $relationData = $this->_getRelationKey($this->baseClass, $v['class']);
            }
            $data[$relationData['relationName']] = $relationData['relationClass']::getHiddenField();

            if (isset($v['subClass'])) {
                $data[$relationData['relationName']] = $this->_normalizeHiddenFieldName($v['subClass'], $data[$relationData['relationName']]);
            }
        }
        return $data;
    }

    private function _normalizeHiddenFieldRelation($class, $subClass = [])
    {
        $result = [
            'class' => $class,
        ];
        $subClassReuslt = [];
        foreach ($subClass as $k => $v) {
            if (is_array($v)) {
                $subClassReuslt[] = $this->_normalizeHiddenFieldRelation($k, $v);
            } else {
                $subClassReuslt[] = $this->_normalizeHiddenFieldRelation($v);
            }
        }
        if (sizeof($subClassReuslt)) {
            $result['subClass'] = $subClassReuslt;
        }

        return $result;
    }

    public function get($table = null, $where = [], $order = null, $select = null, $limit = null, $groupBy = null, $pagination = null)
    {
        if ($this->_searchableResult) {
            if ($this->_isMapping) {
                $this->_searchableResult = $this->loopFunction([$this->_searchableResult])[0];
            }
            $this->_searchableResult = $this->_normalizeHiddenField([$this->_searchableResult])[0];
            return $this->_searchableResult;
        }

        $this->_initQuery($table);
        if ($where) $this->where($where);
        if ($limit) $this->limit($limit);
        if ($select) $this->select($select);
        if ($order) $this->orderBy($order[0], $order[1]);

        if ($pagination) {
            $result = $this->_runQuery('pagination', $pagination);
        } else {
            $result = $this->_runQuery('get');
        }

        if (sizeof($this->_withRelation)) {
            $result = $this->hasOne($result, $this->_withRelation, $this->_isCaching);
        }


        // TEMPORARY
        // if ($this->_keyBy) {
        //     $newResult = [];
        //     foreach ($result as $k => $v) {
        //         $newResult[$v->{$this->_keyBy}] = $v;
        //     }
        //     $result = $newResult;
        // }


        // if ($this->_pluck) {
        //     $newResult = [];
        //     foreach ($result as $k => $v) {
        //         $newResult[] = $v->{$this->_pluck};
        //     }
        //     $result = $newResult;
        // }



        if ($this->_isMapping) {
            $result = $this->loopFunction($result);
        }

        // $hiddenField = $this->baseModel->getHiddenFIeld();
        // foreach ($result as $k => $v) {
        //     foreach ($hiddenField as $ke => $ve) {
        //         if (isset($v->{$ve})) {
        //             unset($result[$k]->{$ve});
        //         }
        //     }
        // }
        $this->_reset_var();

        return $result;
    }


    public function nRelation($data, $relation, $parentClass, $isCaching = false)
    {
        $relationDatas = [];
        $x = 0;
        foreach ($relation as $k => $v) {

            $selectData = [];
            if (is_array($v)) {
                $relationClass = $k;
                $nestedRelation = $v;
            } else {
                $relationClass = $v;
                $nestedRelation = false;
            }

            if (Str::contains($relationClass, ":")) {
                $str = explode(":", $relationClass);
                $relationClass = $str[0];
                $selectData = explode(",", $str[1]);
            }

            $relationKey = isset($this->relationList[$relationClass]) ? $this->relationList[$relationClass] : $this->_getRelationKey($parentClass, $relationClass);
            $relationClass = isModel($relationClass) ? $relationClass : $relationKey['relationClass'];
            $relationTable = $relationKey['relationTable'];
            $relationPk = $relationKey['pk'];
            $relationFk = $relationKey['fk'];
            $relationType = $relationKey['type'];

            $relationFks = [];
            if (!is_object($data)) {
                $relationFks = array_column($data, $relationFk);
            } else {
                if (isset($data->{$relationFk})) {
                    if (!$data->{$relationFk}) {
                        $relationFks[] = null;
                    } else {
                        $relationFks[] = $data->{$relationFk};
                    }
                }
            }


            $crud = new Crud();
            $crud = $crud->init($relationClass, false);

            if ($isCaching) {
                $relationData = $crud->caching()->whereIn($relationPk, $relationFks)->get($relationTable, [], [], $selectData);
            } else {
                if (is_array($relationFks)) {
                    if (sizeof($relationFks) == 0) {
                        $relationFks = null;
                    }
                }
                $relationData = $crud->whereIn($relationPk, $relationFks)->get(null, [], [], $selectData);
            }


            if ($nestedRelation) {
                if (sizeof($relationData) > 0) {
                    $relationData = $this->nRelation($relationData, $nestedRelation, $relationClass, $isCaching);
                } else {
                    $relationData = [];
                }
            }

            $relationDatas[$relationTable] = ['keys' => $relationFks, 'result' => $relationData];
            if (!is_object($data)) {
                foreach ($data as $ke => $ve) {
                    $addData = arraySerach($relationData, $relationPk, $relationFks[$ke], $relationType == 'hasOne');
                    $data[$ke]->{$relationKey['relationName']} = $addData;
                }
            } else {
                $addData = arraySerach($relationData, $relationPk, !$relationFks ? $relationFks : $relationFks[0], $relationType == 'hasOne');
                $data->{$relationKey['relationName']} = $addData;
            }

            $x++;
        }

        return $data;
    }



    // RELATION PURPOSE
    public function hasOne($data, $relation = [], $isCaching = false)
    {

        if (count($relation) == 0) {
            return $data;
        }
        if (!$data) {
            return $data;
        }

        $data = $this->nRelation($data, $relation, $this->baseClass, $isCaching);

        return $data;
    }


    public function _getRelationKey($parentClass, $relationClass)
    {

        if (Str::contains($relationClass, ":")) {
            $str = explode(":", $relationClass);
            $relationClass = $str[0];
            $selectData = explode(",", $str[1]);
        }

        if (isset($this->tableRelation[$parentClass][$relationClass])) {
            $relationKey = $this->tableRelation[$parentClass][$relationClass];
        } else {
            $relationKey = ['type' => 'hasOne'];
        }


        $relationType = $relationKey['type'];
        $relationModel = isModel($relationClass)  ? new $relationClass : new $relationKey['class'];
        $parentModel = new $parentClass;
        $relationTable = $relationModel->getTableName();
        $parentTable = $parentModel->getTableName();

        $relationName = isModel($relationClass) ?  $relationTable : $relationClass;
        $relationClass = isModel($relationClass) ? $relationClass : $relationKey['class'];

        if (substr($relationTable, -1) == "s") {
            $relationTableSingular = substr($relationTable, 0, -1);
        }
        if (substr($parentTable, -1) == "s") {
            $parentTableSingular = substr($parentTable, 0, -1);
        }
        if ($relationType == 'hasOne') {
            if (!isset($relationKey['pk'])) {
                $relationPk = $relationModel->getPrimaryKeyName();
            } else {
                $relationPk = $relationKey['pk'];
            }

            if (!isset($relationKey['fk'])) {
                $relationFk = $relationTableSingular . "_" . $relationPk;
            } else {
                $relationFk = $relationKey['fk'];
            }
        } else {
            if (!isset($relationKey['pk'])) {
                $relationPk = $relationModel->getPrimaryKeyName();
                $relationPk = $parentTableSingular . "_" . $parentModel->getPrimaryKeyName();
            } else {
                $relationPk = $relationKey['pk'];
            }

            if (!isset($relationKey['fk'])) {
                $relationFk = $parentModel->getPrimaryKeyName();
            } else {
                $relationFk = $relationKey['fk'];
            }
        }

        return [
            'relationName' => $relationName,
            'relationModel' => $relationModel,
            'relationClass' => $relationClass,
            'pk' => $relationPk,
            'fk' => $relationFk,
            'relationTable' => $relationTable,
            'type' => $relationType,
            'connectionName' => $relationModel->getConName()
        ];
    }


    private function _reset_var()
    {
        // $this->_whereIn = [];
        // $this->_withRelation = [];
        // $this->_like = [];
        // $this->_keyBy = null;
        // $this->_tableName = null;
        // $this->_pluck = null;
        // $this->_join = null;
        // $this->_enablePaging = false;
        // $this->_isCaching = false;
        // $this->_limit = null;
        // $this->_where = null;
    }
}
