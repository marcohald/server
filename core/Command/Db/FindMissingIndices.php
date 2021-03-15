<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Core\Command\Db;


use OC\Core\Command\Base;
use OC\DB\Connection;
use OC\DB\Exceptions\DbalException;
use OC\DB\SchemaWrapper;
use OCP\DB\Types;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FindMissingIndices extends Base {

	/** @var Connection */
	private $connection;

	public function __construct(Connection $connection) {
		parent::__construct();

		$this->connection = $connection;
	}

	protected function configure() {
		$this
			->setName('db:test-missing-indices')
			->setDescription('Inserts random data into a table so you can find missing indices')
			->addArgument(
				'table',
				InputArgument::REQUIRED,
				'Table to be filled'
			)
			->addOption(
				'count',
				'c',
				InputOption::VALUE_REQUIRED,
				'Number of rows to add',
				100000
			)
		;
	}

	protected function getValues(string $type, bool $notNull): array {
		$values = [];
		switch ($type) {
			case Types::BIGINT:
			case Types::INTEGER:
			case Types::SMALLINT:
			case Types::DECIMAL:
			case Types::FLOAT:
				$values[] = 2;
			case Types::BOOLEAN:
				$values[] = 1;
				$values[] = 0;
				break;

			case Types::STRING:
			case Types::TEXT:
				$values[] = 'a';
				$values[] = 'b';
				break;

			case Types::DATE:
				$values[] = '2021-03-12';
				$values[] = '2021-03-13';
				break;

			case Types::TIME:
				$values[] = '20:34';
				$values[] = '05:13';
				break;

			case Types::DATETIME:
				$values[] = '2021-03-12 20:34';
				$values[] = '2021-03-13 05:13';
				break;
		}

		if (!$notNull) {
			$values[] = null;
		}

		return $values;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$tableName = $input->getArgument('table');
		$numberOfEntries = (int) $input->getOption('count');
		if (strpos($tableName, 'oc_') === 0) {
			$tableName = substr($tableName, 3);
		}

		$schema = new SchemaWrapper($this->connection);
		$table = $schema->getTable($tableName);


		$insert = $this->connection->getQueryBuilder();
		$insert->insert($tableName);

		$columns = $table->getColumns();
		foreach ($columns as $column) {
			$insert->setValue($column->getName(), $insert->createParameter($column->getName()));
		}

		$this->connection->beginTransaction();
		for ($i = 0; $i < $numberOfEntries; $i++) {
			foreach ($columns as $column) {
				if ($column->getAutoincrement()) {
					continue;
				}

				$values = $this->getValues($column->getType()->getName(), $column->getNotnull());

				$insert->setParameter(
					$column->getName(),
					$values[$i % count($values)],
					$column->getType()->getBindingType()
				);
			}
			try {
				$insert->executeUpdate();
			} catch (DbalException $e) {
				$output->writeln($e->getMessage());
			}
		}
		$this->connection->commit();

		return 0;
	}
}
