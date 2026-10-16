<?php
/**
 * Endpoint paths.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Paths below the API base URL. Nothing else is ever requested.
 */
final class Endpoints {

	public const STATUS      = '/status';
	public const DSE         = '/dse';
	public const SCAN        = '/scan';
	public const SCAN_STATUS = '/scan-status';
	public const FREE_SCAN   = '/free-scan';

	/**
	 * Logical key for polling a free scan job. The real path is FREE_SCAN/{job_id}.
	 */
	public const FREE_SCAN_JOB = 'free-scan-job';
}
