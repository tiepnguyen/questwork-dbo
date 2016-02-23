<?php
namespace Questwork\Interfaces;

interface Database
{
	public function dbo();

	public function query($command);

	public function exec($command);

	public function prepare($command, $params);

	public function lastInsertId();

	public function buildSelect($table, $fields, $where, $order, $limit);

	public function parseCondition(&$where);

	public function select($table, $fields, $where, $order, $limit);

	public function selectOne($table, $fields, $where, $order);

	public function count($table, $where);

	public function insert($table, $fields, $batch);

	public function update($table, $fields, $where);

	public function delete($table, $where);
}