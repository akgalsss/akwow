<?php

class Application_Model_Geo
{
    public $id;
    public $code;
    public $name_uk;
    public $name_ru;
    public $name_en;

    public $name;

    public function getAll($pattern = "", $lang="uk") {
        $dbItem = new Application_Model_DbTable_Geo();
        if ($pattern !== "")
            $pattern .= ".";
        $res = $dbItem->fetchAll('code LIKE "' . $pattern . '_" OR code LIKE "' . $pattern . '__"');

        $itemsArr = $res->toArray();

        $resArr = array();
        foreach ($itemsArr as $value) {
            $resArr[$value["code"]] = $value["name_" . $lang];
        }

        return $resArr;
    }

    public function getAllChild($pattern = "", $lang="uk") {
        $dbItem = new Application_Model_DbTable_Geo();
        $originalPattern = $pattern;
        if ($pattern !== "")
            $pattern .= ".";
        $res = $dbItem->fetchAll('code LIKE "' . $pattern . '_" OR code LIKE "' . $pattern . '__"');

        $itemsArr = $res->toArray();

        $resArr = array(array(
            "value" => $originalPattern,
            "option" => "Любой"
        ));
        foreach ($itemsArr as $value) {
            $resArr[] = array(
                "value" => $value["code"],
                "option" => $value["name_" . $lang]
            );
        }

        return $resArr;
    }

    public function getFullGeoName ($geoCode = "") {
        $indexes = explode(".", $geoCode);

        $condList = array();
        $tmp = "";
        foreach ($indexes as $val) {
            $condList[] = $tmp . $val;
            $tmp .= $val . ".";
        }

        $dbItem = new Application_Model_DbTable_Geo();
        $res = $dbItem->fetchAll('code IN ("' . implode ('","', $condList) . '")')->toArray();

        $result = array();
        foreach ($res as $value) {
            $result[] = $value["name_uk"];
        }
        return implode(", ", $result);

    }
}

