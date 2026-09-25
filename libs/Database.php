<?php

// Database เป็นตัวเรียก Audit จึงต้องมั่นใจว่าคลาสถูกโหลดเสมอ —
// สคริปต์ CLI บางตัว require libs/Database.php ตรง ๆ โดยไม่ผ่าน index.php
require_once __DIR__ . '/Audit.php';

class Database extends PDO {

    public function __construct($dsn = null, $username = null, $passwd = null, $options = null) {
        if (!is_null($dsn)) {
            parent::__construct($dsn, $username, $passwd, $options);
            $this->exec("SET NAMES utf8mb4");
            // ให้ CURRENT_TIMESTAMP / NOW() ของฐานตรงกับเวลาไทยที่ PHP ใช้
            $this->exec("SET time_zone = '+07:00'");
        }
    }

    public function select($sql, $array = array(), $fetchMode = PDO::FETCH_ASSOC) {
        $sth = $this->prepare($sql);
        foreach ($array as $key => $value) {
            $sth->bindValue("$key", $value, self::paramType($value));
        }
        $sth->execute();
        return $sth->fetchAll($fetchMode);
    }

    public function selectOne($sql, $array = array(), $fetchMode = PDO::FETCH_ASSOC) {
        $rows = $this->select($sql, $array, $fetchMode);
        return !empty($rows) ? $rows[0] : false;
    }

    /** ค่าเดียว เช่น COUNT(*) */
    public function selectValue($sql, $array = array()) {
        $row = $this->selectOne($sql, $array, PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    public function insert($table, $data) {
        ksort($data);
        $fieldNames = implode('`, `', array_keys($data));
        $fieldValues = ':' . implode(', :', array_keys($data));
        $sth = $this->prepare("INSERT INTO $table (`$fieldNames`) VALUES ($fieldValues)");
        foreach ($data as $key => $value) {
            $sth->bindValue(":$key", $value, self::paramType($value));
        }
        $sth->execute();
        $id = (int) $this->lastInsertId();
        Audit::afterInsert($this, $table, $data, $id);
        return $id;
    }

    /**
     * @param string $where        เงื่อนไข เช่น 'zone_id = :w_id' (ตั้งชื่อพารามิเตอร์ขึ้นต้น :w_ กันชนกับคอลัมน์)
     * @param array  $whereParams  ค่าของพารามิเตอร์ใน $where
     */
    public function update($table, $data, $where, $whereParams = array()) {
        ksort($data);
        // อ่านค่าเดิมไว้ก่อน เพราะพอ UPDATE ผ่านไปแล้วจะไม่มีทางรู้ว่าเดิมเป็นอะไร
        $before = Audit::before($this, $table, $where, 0, $whereParams);
        $fieldDetails = NULL;
        foreach ($data as $key => $value) {
            $fieldDetails .= "`$key`=:$key,";
        }
        $fieldDetails = rtrim($fieldDetails, ',');
        $sth = $this->prepare("UPDATE $table SET $fieldDetails WHERE $where");
        foreach ($data as $key => $value) {
            $sth->bindValue(":$key", $value, self::paramType($value));
        }
        foreach ($whereParams as $key => $value) {
            $sth->bindValue($key, $value, self::paramType($value));
        }
        $sth->execute();
        Audit::afterUpdate($this, $table, $data, $where, $before);
        return $sth->rowCount();
    }

    public function delete($table, $where, $limit = 1, $whereParams = array()) {
        // เก็บสำเนาทั้งแถว (พร้อมลูกที่ผูก ON DELETE CASCADE) ไว้ใน audit log ก่อนลบ
        $before = Audit::beforeDelete($this, $table, $where, $limit, $whereParams);
        $sth = $this->prepare("DELETE FROM $table WHERE $where LIMIT " . (int) $limit);
        foreach ($whereParams as $key => $value) {
            $sth->bindValue($key, $value, self::paramType($value));
        }
        $sth->execute();
        Audit::afterDelete($this, $table, $where, $before);
        return $sth->rowCount();
    }

    private static function paramType($value) {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        return PDO::PARAM_STR;
    }

}
