<?php
/**
 * Storage failure.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * A storage target could not write or delete an object. The message goes
 * to the PHP error log only; the public status file names the class.
 */
class StorageError extends \RuntimeException {
}
