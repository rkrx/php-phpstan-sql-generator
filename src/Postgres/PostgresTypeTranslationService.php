<?php

namespace Kir\PhpstanTypesFromSql\Postgres;

use RuntimeException;

abstract class PostgresTypeTranslationService {
	public static function asPhpType(string $type, bool $nullable): string {
		$type = match (strtolower($type)) {
			'boolean' => 'bool',
			'integer', 'bigint', 'smallint' => 'int',
			'numeric', 'real', 'double precision', 'decimal' => 'float',
			'character varying', 'varchar', 'character', 'char', 'text',
			'date', 'time', 'timestamp', 'timestamp without time zone', 'timestamp with time zone',
			'bytea', 'uuid', 'json', 'jsonb', 'xml', 'point', 'line', 'lseg', 'box', 'path', 'polygon', 'circle',
			'bit', 'bit varying', 'interval', 'money', 'macaddr', 'inet', 'cidr', 'tsquery', 'tsvector' => 'string',
			'array' => 'list<scalar>',
			default => throw new RuntimeException("Unknown type: {$type}"),
		};
		
		if($nullable) {
			$type .= '|null';
		}
		
		return $type;
	}
}