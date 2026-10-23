<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish\Fakes;

use WPHeadless\StaticPublish\Routes;

/** Routes from a table. */
final class FakeRoutes extends Routes {
	/** @var array<int, array{path:string, id:int, type:string}> */
	public array $singulars = array();

	/** @var array<int,string> */
	public array $archives = array();

	/** @var array<int,string> */
	public array $files = array();

	/** @var \Throwable|null Thrown by singulars() when set. */
	public ?\Throwable $throw = null;

	public function singulars(): array {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		return $this->singulars;
	}

	public function archives(): array {
		return $this->archives;
	}

	public function files(): array {
		return $this->files;
	}
}
