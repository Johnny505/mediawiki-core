<?php
/**
 *
 *
 * Created on Oct 16, 2006
 *
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Query action to List the log events, with optional filtering by various parameters.
 *
 * @ingroup API
 */
class ApiQueryLogEvents extends ApiQueryBase {

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'le' );
	}

	private $fld_ids = false, $fld_title = false, $fld_type = false,
		$fld_action = false, $fld_user = false, $fld_userid = false,
		$fld_timestamp = false, $fld_comment = false, $fld_parsedcomment = false,
		$fld_details = false, $fld_tags = false;

	public function execute() {
		$params = $this->extractRequestParams();
		$db = $this->getDB();

		$prop = array_flip( $params['prop'] );

		$this->fld_ids = isset( $prop['ids'] );
		$this->fld_title = isset( $prop['title'] );
		$this->fld_type = isset( $prop['type'] );
		$this->fld_action = isset( $prop['action'] );
		$this->fld_user = isset( $prop['user'] );
		$this->fld_userid = isset( $prop['userid'] );
		$this->fld_timestamp = isset( $prop['timestamp'] );
		$this->fld_comment = isset( $prop['comment'] );
		$this->fld_parsedcomment = isset( $prop['parsedcomment'] );
		$this->fld_details = isset( $prop['details'] );
		$this->fld_tags = isset( $prop['tags'] );

		$hideLogs = LogEventsList::getExcludeClause( $db, 'user', $this->getUser() );
		if ( $hideLogs !== false ) {
			$this->addWhere( $hideLogs );
		}

		// Order is significant here
		$this->addTables( array( 'logging', 'user', 'page' ) );
		$this->addOption( 'STRAIGHT_JOIN' );
		$this->addJoinConds( array(
			'user' => array( 'JOIN',
				'user_id=log_user' ),
			'page' => array( 'LEFT JOIN',
				array( 'log_namespace=page_namespace',
					'log_title=page_title' ) ) ) );
		$index = array( 'logging' => 'times' ); // default, may change

		$this->addFields( array(
			'log_type',
			'log_action',
			'log_timestamp',
			'log_deleted',
		) );

		$this->addFieldsIf( array( 'log_id', 'page_id' ), $this->fld_ids );
		$this->addFieldsIf( array( 'log_user', 'user_name' ), $this->fld_user );
		$this->addFieldsIf( 'user_id', $this->fld_userid );
		$this->addFieldsIf( array( 'log_namespace', 'log_title' ), $this->fld_title || $this->fld_parsedcomment );
		$this->addFieldsIf( 'log_comment', $this->fld_comment || $this->fld_parsedcomment );
		$this->addFieldsIf( 'log_params', $this->fld_details );

		if ( $this->fld_tags ) {
			$this->addTables( 'tag_summary' );
			$this->addJoinConds( array( 'tag_summary' => array( 'LEFT JOIN', 'log_id=ts_log_id' ) ) );
			$this->addFields( 'ts_tags' );
		}

		if ( !is_null( $params['tag'] ) ) {
			$this->addTables( 'change_tag' );
			$this->addJoinConds( array( 'change_tag' => array( 'INNER JOIN', array( 'log_id=ct_log_id' ) ) ) );
			$this->addWhereFld( 'ct_tag', $params['tag'] );
			global $wgOldChangeTagsIndex;
			$index['change_tag'] = $wgOldChangeTagsIndex ? 'ct_tag' : 'change_tag_tag_id';
		}

		if ( !is_null( $params['action'] ) ) {
			list( $type, $action ) = explode( '/', $params['action'] );
			$this->addWhereFld( 'log_type', $type );
			$this->addWhereFld( 'log_action', $action );
		} elseif ( !is_null( $params['type'] ) ) {
			$this->addWhereFld( 'log_type', $params['type'] );
			$index['logging'] = 'type_time';
		}

		$this->addTimestampWhereRange( 'log_timestamp', $params['dir'], $params['start'], $params['end'] );

		$limit = $params['limit'];
		$this->addOption( 'LIMIT', $limit + 1 );

		$user = $params['user'];
		if ( !is_null( $user ) ) {
			$userid = User::idFromName( $user );
			if ( !$userid ) {
				$this->dieUsage( "User name $user not found", 'param_user' );
			}
			$this->addWhereFld( 'log_user', $userid );
			$index['logging'] = 'user_time';
		}

		$title = $params['title'];
		if ( !is_null( $title ) ) {
			$titleObj = Title::newFromText( $title );
			if ( is_null( $titleObj ) ) {
				$this->dieUsage( "Bad title value '$title'", 'param_title' );
			}
			$this->addWhereFld( 'log_namespace', $titleObj->getNamespace() );
			$this->addWhereFld( 'log_title', $titleObj->getDBkey() );

			// Use the title index in preference to the user index if there is a conflict
			$index['logging'] = is_null( $user ) ? 'page_time' : array( 'page_time', 'user_time' );
		}

		$prefix = $params['prefix'];

		if ( !is_null( $prefix ) ) {
			global $wgMiserMode;
			if ( $wgMiserMode ) {
				$this->dieUsage( 'Prefix search disabled in Miser Mode', 'prefixsearchdisabled' );
			}

			$title = Title::newFromText( $prefix );
			if ( is_null( $title ) ) {
				$this->dieUsage( "Bad title value '$prefix'", 'param_prefix' );
			}
			$this->addWhereFld( 'log_namespace', $title->getNamespace() );
			$this->addWhere( 'log_title ' . $db->buildLike( $title->getDBkey(), $db->anyString() ) );
		}

		$this->addOption( 'USE INDEX', $index );

		// Paranoia: avoid brute force searches (bug 17342)
		if ( !is_null( $title ) ) {
			$this->addWhere( $db->bitAnd( 'log_deleted', LogPage::DELETED_ACTION ) . ' = 0' );
		}
		if ( !is_null( $user ) ) {
			$this->addWhere( $db->bitAnd( 'log_deleted', LogPage::DELETED_USER ) . ' = 0' );
		}

		$count = 0;
		$res = $this->select( __METHOD__ );
		$result = $this->getResult();
		foreach ( $res as $row ) {
			if ( ++ $count > $limit ) {
				// We've reached the one extra which shows that there are additional pages to be had. Stop here...
				$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->log_timestamp ) );
				break;
			}

			$vals = $this->extractRowInfo( $row );
			if ( !$vals ) {
				continue;
			}
			$fit = $result->addValue( array( 'query', $this->getModuleName() ), null, $vals );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->log_timestamp ) );
				break;
			}
		}
		$result->setIndexedTagName_internal( array( 'query', $this->getModuleName() ), 'item' );
	}

	/**
	 * @param $result ApiResult
	 * @param $vals array
	 * @param $params string
	 * @param $type string
	 * @param $action string
	 * @param $ts
	 * @param $legacy bool
	 * @return array
	 */
	public static function addLogParams( $result, &$vals, $params, $type, $action, $ts, $legacy = false ) {
		switch ( $type ) {
			case 'move':
				if ( $legacy ) {
					$targetKey = 0;
					$noredirKey = 1;
				} else {
					$targetKey = '4::target';
					$noredirKey = '5::noredir';
				}

				if ( isset( $params[$targetKey] ) ) {
					$title = Title::newFromText( $params[$targetKey] );
					if ( $title ) {
						$vals2 = array();
						ApiQueryBase::addTitleInfo( $vals2, $title, 'new_' );
						$vals[$type] = $vals2;
					}
				}
				if ( isset( $params[$noredirKey] ) && $params[$noredirKey] ) {
					$vals[$type]['suppressedredirect'] = '';
				}
				$params = null;
				break;
			case 'patrol':
				if ( $legacy ) {
					$cur = 0;
					$prev = 1;
					$auto = 2;
				} else {
					$cur = '4::curid';
					$prev = '5::previd';
					$auto = '6::auto';
				}
				$vals2 = array();
				$vals2['cur'] = $params[$cur];
				$vals2['prev'] = $params[$prev];
				$vals2['auto'] = $params[$auto];
				$vals[$type] = $vals2;
				$params = null;
				break;
			case 'rights':
				$vals2 = array();
				if ( $legacy ) {
					list( $vals2['old'], $vals2['new'] ) = $params;
				} else {
					$vals2['new'] = implode( ', ', $params['5::newgroups'] );
					$vals2['old'] = implode( ', ', $params['4::oldgroups'] );
				}
				$vals[$type] = $vals2;
				$params = null;
				break;
			case 'block':
				if ( $action == 'unblock' ) {
					break;
				}
				$vals2 = array();
				list( $vals2['duration'], $vals2['flags'] ) = $params;

				// Indefinite blocks have no expiry time
				if ( SpecialBlock::parseExpiryInput( $params[0] ) !== wfGetDB( DB_SLAVE )->getInfinity() ) {
					$vals2['expiry'] = wfTimestamp( TS_ISO_8601,
						strtotime( $params[0], wfTimestamp( TS_UNIX, $ts ) ) );
				}
				$vals[$type] = $vals2;
				$params = null;
				break;
			case 'upload':
				if ( isset( $params['img_timestamp'] ) ) {
					$params['img_timestamp'] = wfTimestamp( TS_ISO_8601, $params['img_timestamp'] );
				}
				break;
		}
		if ( !is_null( $params ) ) {
			$logParams = array();
			// Keys like "4::paramname" can't be used for output so we change them to "paramname"
			foreach ( $params as $key => $value ) {
				if ( strpos( $key, ':' ) === false ) {
					$logParams[$key] = $value;
					continue;
				}
				$logParam = explode( ':', $key, 3 );
				$logParams[$logParam[2]] = $value;
			}
			$result->setIndexedTagName( $logParams, 'param' );
			$result->setIndexedTagName_recursive( $logParams, 'param' );
			$vals = array_merge( $vals, $logParams );
		}
		return $vals;
	}

	private function extractRowInfo( $row ) {
		$logEntry = DatabaseLogEntry::newFromRow( $row );
		$vals = array();

		if ( $this->fld_ids ) {
			$vals['logid'] = intval( $row->log_id );
			$vals['pageid'] = intval( $row->page_id );
		}

		if ( $this->fld_title || $this->fld_parsedcomment ) {
			$title = Title::makeTitle( $row->log_namespace, $row->log_title );
		}

		if ( $this->fld_title ) {
			if ( LogEventsList::isDeleted( $row, LogPage::DELETED_ACTION ) ) {
				$vals['actionhidden'] = '';
			} else {
				ApiQueryBase::addTitleInfo( $vals, $title );
			}
		}

		if ( $this->fld_type || $this->fld_action ) {
			$vals['type'] = $row->log_type;
			$vals['action'] = $row->log_action;
		}

		if ( $this->fld_details && $row->log_params !== '' ) {
			if ( LogEventsList::isDeleted( $row, LogPage::DELETED_ACTION ) ) {
				$vals['actionhidden'] = '';
			} else {
				self::addLogParams(
					$this->getResult(),
					$vals,
					$logEntry->getParameters(),
					$logEntry->getType(),
					$logEntry->getSubtype(),
					$logEntry->getTimestamp(),
					$logEntry->isLegacy()
				);
			}
		}

		if ( $this->fld_user || $this->fld_userid ) {
			if ( LogEventsList::isDeleted( $row, LogPage::DELETED_USER ) ) {
				$vals['userhidden'] = '';
			} else {
				if ( $this->fld_user ) {
					$vals['user'] = $row->user_name;
				}
				if ( $this->fld_userid ) {
					$vals['userid'] = $row->user_id;
				}

				if ( !$row->log_user ) {
					$vals['anon'] = '';
				}
			}
		}
		if ( $this->fld_timestamp ) {
			$vals['timestamp'] = wfTimestamp( TS_ISO_8601, $row->log_timestamp );
		}

		if ( ( $this->fld_comment || $this->fld_parsedcomment ) && isset( $row->log_comment ) ) {
			if ( LogEventsList::isDeleted( $row, LogPage::DELETED_COMMENT ) ) {
				$vals['commenthidden'] = '';
			} else {
				if ( $this->fld_comment ) {
					$vals['comment'] = $row->log_comment;
				}

				if ( $this->fld_parsedcomment ) {
					$vals['parsedcomment'] = Linker::formatComment( $row->log_comment, $title );
				}
			}
		}

		if ( $this->fld_tags ) {
			if ( $row->ts_tags ) {
				$tags = explode( ',', $row->ts_tags );
				$this->getResult()->setIndexedTagName( $tags, 'tag' );
				$vals['tags'] = $tags;
			} else {
				$vals['tags'] = array();
			}
		}

		return $vals;
	}

	public function getCacheMode( $params ) {
		if ( !is_null( $params['prop'] ) && in_array( 'parsedcomment', $params['prop'] ) ) {
			// formatComment() calls wfMessage() among other things
			return 'anon-public-user-private';
		} elseif ( LogEventsList::getExcludeClause( $this->getDB(), 'user', $this->getUser() )
			=== LogEventsList::getExcludeClause( $this->getDB(), 'public' )
		) { // Output can only contain public data.
			return 'public';
		} else {
			return 'anon-public-user-private';
		}
	}

	public function getAllowedParams() {
		global $wgLogTypes, $wgLogActions, $wgLogActionsHandlers;
		return array(
			'prop' => array(
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'ids|title|type|user|timestamp|comment|details',
				ApiBase::PARAM_TYPE => array(
					'ids',
					'title',
					'type',
					'user',
					'userid',
					'timestamp',
					'comment',
					'parsedcomment',
					'details',
					'tags'
				)
			),
			'type' => array(
				ApiBase::PARAM_TYPE => $wgLogTypes
			),
			'action' => array(
				ApiBase::PARAM_TYPE => array_keys( array_merge( $wgLogActions, $wgLogActionsHandlers ) )
			),
			'start' => array(
				ApiBase::PARAM_TYPE => 'timestamp'
			),
			'end' => array(
				ApiBase::PARAM_TYPE => 'timestamp'
			),
			'dir' => array(
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_TYPE => array(
					'newer',
					'older'
				)
			),
			'user' => null,
			'title' => null,
			'prefix' => null,
			'tag' => null,
			'limit' => array(
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			)
		);
	}

	public function getParamDescription() {
		$p = $this->getModulePrefix();
		return array(
			'prop' => array(
				'Which properties to get',
				' ids            - Adds the ID of the log event',
				' title          - Adds the title of the page for the log event',
				' type           - Adds the type of log event',
				' user           - Adds the user responsible for the log event',
				' userid         - Adds the user ID who was responsible for the log event',
				' timestamp      - Adds the timestamp for the event',
				' comment        - Adds the comment of the event',
				' parsedcomment  - Adds the parsed comment of the event',
				' details        - Lists additional details about the event',
				' tags           - Lists tags for the event',
			),
			'type' => 'Filter log entries to only this type',
			'action' => "Filter log actions to only this type. Overrides {$p}type",
			'start' => 'The timestamp to start enumerating from',
			'end' => 'The timestamp to end enumerating',
			'dir' => $this->getDirectionDescription( $p ),
			'user' => 'Filter entries to those made by the given user',
			'title' => 'Filter entries to those related to a page',
			'prefix' => 'Filter entries that start with this prefix. Disabled in Miser Mode',
			'limit' => 'How many total event entries to return',
			'tag' => 'Only list event entries tagged with this tag',
		);
	}

	public function getResultProperties() {
		global $wgLogTypes;
		return array(
			'ids' => array(
				'logid' => 'integer',
				'pageid' => 'integer'
			),
			'title' => array(
				'ns' => 'namespace',
				'title' => 'string'
			),
			'type' => array(
				'type' => array(
					ApiBase::PROP_TYPE => $wgLogTypes
				),
				'action' => 'string'
			),
			'details' => array(
				'actionhidden' => 'boolean'
			),
			'user' => array(
				'userhidden' => 'boolean',
				'user' => array(
					ApiBase::PROP_TYPE => 'string',
					ApiBase::PROP_NULLABLE => true
				),
				'anon' => 'boolean'
			),
			'userid' => array(
				'userhidden' => 'boolean',
				'userid' => array(
					ApiBase::PROP_TYPE => 'integer',
					ApiBase::PROP_NULLABLE => true
				),
				'anon' => 'boolean'
			),
			'timestamp' => array(
				'timestamp' => 'timestamp'
			),
			'comment' => array(
				'commenthidden' => 'boolean',
				'comment' => array(
					ApiBase::PROP_TYPE => 'string',
					ApiBase::PROP_NULLABLE => true
				)
			),
			'parsedcomment' => array(
				'commenthidden' => 'boolean',
				'parsedcomment' => array(
					ApiBase::PROP_TYPE => 'string',
					ApiBase::PROP_NULLABLE => true
				)
			)
		);
	}

	public function getDescription() {
		return 'Get events from logs';
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'code' => 'param_user', 'info' => 'User name $user not found' ),
			array( 'code' => 'param_title', 'info' => 'Bad title value \'title\'' ),
			array( 'code' => 'param_prefix', 'info' => 'Bad title value \'prefix\'' ),
			array( 'code' => 'prefixsearchdisabled', 'info' => 'Prefix search disabled in Miser Mode' ),
		) );
	}

	public function getExamples() {
		return array(
			'api.php?action=query&list=logevents'
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Logevents';
	}
}
