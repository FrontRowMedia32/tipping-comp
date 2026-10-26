<?php
// Mock HTTP responses for each provider, shaped like the real feeds.
function tipc_test_iso( $offset_hours ) { return gmdate( 'Y-m-d\TH:i:s\Z', time() + $offset_hours * 3600 ); }

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['tipc_test_urls'][] = $url;
	if ( false !== $pre ) { return $pre; }
	$GLOBALS['tipc_test_ua'] = $args['user-agent'];
	$GLOBALS['tipc_test_headers'][] = $args['headers'];
	$body = null;

	if ( false !== strpos( $url, 'api.squiggle.com.au' ) ) {
		if ( false !== strpos( $url, 'q=teams' ) ) {
			$body = array( 'teams' => array(
				array( 'id' => 1, 'name' => 'Adelaide', 'logo' => '/images/logos/adelaide.png' ),
				array( 'id' => 2, 'name' => 'Brisbane Lions', 'logo' => '/images/logos/brisbane.png' ),
				array( 'id' => 3, 'name' => 'Carlton', 'logo' => '/images/logos/carlton.png' ),
				array( 'id' => 4, 'name' => 'Collingwood', 'logo' => '/images/logos/collingwood.png' ),
			) );
		} else {
			$games = array(
				// Standard API style: names + ids, local date + tz.
				array( 'id' => 101, 'round' => 1, 'roundname' => 'Round 1', 'hteam' => 'Adelaide', 'hteamid' => 1, 'ateam' => 'Brisbane Lions', 'ateamid' => 2,
					'date' => wp_date( 'Y-m-d H:i:s', time() - 30 * 3600, new DateTimeZone( 'Australia/Adelaide' ) ), 'tz' => wp_date( 'P', time(), new DateTimeZone( 'Australia/Adelaide' ) ),
					'complete' => 100, 'hscore' => 88, 'ascore' => 70, 'venue' => 'Adelaide Oval', 'winner' => 'Adelaide' ),
				array( 'id' => 102, 'round' => 1, 'roundname' => 'Round 1', 'hteam' => 'Carlton', 'hteamid' => 3, 'ateam' => 'Collingwood', 'ateamid' => 4,
					'unixtime' => time() - 26 * 3600, 'date' => 'x', 'complete' => 100, 'hscore' => 75, 'ascore' => 75, 'venue' => 'M.C.G.' ),
				array( 'id' => 103, 'round' => 2, 'roundname' => 'Round 2', 'hteam' => 'Brisbane Lions', 'hteamid' => 2, 'ateam' => 'Carlton', 'ateamid' => 3,
					'unixtime' => time() - 1 * 3600, 'complete' => 40, 'hscore' => 30, 'ascore' => 22, 'venue' => 'Gabba' ),
				array( 'id' => 104, 'round' => 2, 'roundname' => 'Round 2', 'hteam' => 'Collingwood', 'hteamid' => 4, 'ateam' => 'Adelaide', 'ateamid' => 1,
					'unixtime' => time() + 48 * 3600, 'complete' => 0, 'hscore' => 0, 'ascore' => 0, 'venue' => 'M.C.G.' ),
				// Event-API style: numeric team ids, ISO date.
				array( 'id' => 105, 'round' => 2, 'hteam' => 1, 'ateam' => 3, 'date' => tipc_test_iso( 72 ), 'tz' => '+10:30', 'complete' => 0, 'venue' => 'Adelaide Oval' ),
			);
			if ( preg_match( '/round=(\d+)/', $url, $m ) ) {
				$games = array_values( array_filter( $games, function ( $g ) use ( $m ) { return (int) $g['round'] === (int) $m[1]; } ) );
			}
			$body = array( 'games' => $games );
		}
	} elseif ( false !== strpos( $url, 'nrl.com/draw/data' ) ) {
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$r = (int) $q['round'];
		$fx = array(
			1 => array(
				array( 'type' => 'Match', 'matchMode' => 'Post', 'matchState' => 'FullTime', 'roundTitle' => 'Round 1', 'venue' => 'Allegiant Stadium',
					'matchCentreUrl' => '/draw/nrl-premiership/2026/round-1/sea-eagles-v-rabbitohs/',
					'homeTeam' => array( 'nickName' => 'Sea Eagles', 'score' => 36, 'theme' => array( 'key' => 'sea-eagles', 'logos' => array( 'badge.png' => '/.theme/sea-eagles/badge.png' ) ) ),
					'awayTeam' => array( 'nickName' => 'Rabbitohs', 'score' => 24, 'theme' => array( 'key' => 'rabbitohs', 'logos' => array( 'badge.png' => '/.theme/rabbitohs/badge.png' ) ) ),
					'clock' => array( 'kickOffTimeLong' => tipc_test_iso( -50 ) ) ),
				array( 'type' => 'Match', 'matchMode' => 'Post', 'matchState' => 'FullTime', 'roundTitle' => 'Round 1', 'venue' => 'Accor Stadium',
					'matchCentreUrl' => '/draw/nrl-premiership/2026/round-1/bulldogs-v-roosters/',
					'homeTeam' => array( 'nickName' => 'Bulldogs', 'score' => 10 ), 'awayTeam' => array( 'nickName' => 'Roosters', 'score' => 22 ),
					'clock' => array( 'kickOffTimeLong' => tipc_test_iso( -48 ) ) ),
				array( 'type' => 'Bye', 'teamNickName' => 'Storm' ),
			),
			2 => array(
				array( 'type' => 'Match', 'matchMode' => 'Pre', 'matchState' => 'Upcoming', 'roundTitle' => 'Round 2', 'venue' => '4 Pines Park',
					'matchCentreUrl' => '/draw/nrl-premiership/2026/round-2/sea-eagles-v-bulldogs/',
					'homeTeam' => array( 'nickName' => 'Sea Eagles' ), 'awayTeam' => array( 'nickName' => 'Bulldogs' ),
					'clock' => array( 'kickOffTimeLong' => tipc_test_iso( 30 ) ) ),
				array( 'type' => 'Match', 'matchMode' => 'Pre', 'matchState' => 'Upcoming', 'roundTitle' => 'Round 2', 'venue' => 'Allianz Stadium',
					'matchCentreUrl' => '/draw/nrl-premiership/2026/round-2/roosters-v-rabbitohs/',
					'homeTeam' => array( 'nickName' => 'Roosters' ), 'awayTeam' => array( 'nickName' => 'Rabbitohs' ),
					'clock' => array( 'kickOffTimeLong' => tipc_test_iso( 54 ) ) ),
			),
		);
		$body = array(
			'filterRounds' => array( array( 'name' => 'Round 1', 'value' => 1 ), array( 'name' => 'Round 2', 'value' => 2 ) ),
			'fixtures' => isset( $fx[ $r ] ) ? $fx[ $r ] : array(),
		);
	} elseif ( false !== strpos( $url, 'site.api.espn.com' ) ) {
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$ev = array();
		if ( '2' === $q['seasontype'] && '1' === $q['week'] ) {
			$ev[] = array( 'id' => '401', 'date' => gmdate( 'Y-m-d\TH:i\Z', time() - 40 * 3600 ),
				'status' => array( 'type' => array( 'name' => 'STATUS_FINAL', 'state' => 'post', 'completed' => true ) ),
				'competitions' => array( array( 'venue' => array( 'fullName' => 'Arrowhead Stadium' ), 'competitors' => array(
					array( 'homeAway' => 'home', 'score' => '27', 'winner' => true, 'team' => array( 'displayName' => 'Kansas City Chiefs', 'logo' => 'https://a.espncdn.com/i/teamlogos/nfl/500/kc.png' ) ),
					array( 'homeAway' => 'away', 'score' => '20', 'winner' => false, 'team' => array( 'displayName' => 'Baltimore Ravens', 'logo' => 'https://a.espncdn.com/i/teamlogos/nfl/500/bal.png' ) ),
				) ) ) );
			$ev[] = array( 'id' => '402', 'date' => gmdate( 'Y-m-d\TH:i\Z', time() - 36 * 3600 ),
				'status' => array( 'type' => array( 'name' => 'STATUS_FINAL', 'state' => 'post', 'completed' => true ) ),
				'competitions' => array( array( 'competitors' => array(
					array( 'homeAway' => 'home', 'score' => '17', 'winner' => false, 'team' => array( 'displayName' => 'Green Bay Packers' ) ),
					array( 'homeAway' => 'away', 'score' => '17', 'winner' => false, 'team' => array( 'displayName' => 'Chicago Bears' ) ),
				) ) ) );
		}
		if ( '2' === $q['seasontype'] && '2' === $q['week'] ) {
			$ev[] = array( 'id' => '403', 'date' => gmdate( 'Y-m-d\TH:i\Z', time() + 80 * 3600 ),
				'status' => array( 'type' => array( 'name' => 'STATUS_SCHEDULED', 'state' => 'pre', 'completed' => false ) ),
				'competitions' => array( array( 'competitors' => array(
					array( 'homeAway' => 'home', 'score' => '0', 'team' => array( 'displayName' => 'Buffalo Bills' ) ),
					array( 'homeAway' => 'away', 'score' => '0', 'team' => array( 'displayName' => 'Miami Dolphins' ) ),
				) ) ) );
		}
		$body = array( 'events' => $ev );
	} elseif ( false !== strpos( $url, 'api.football-data.org' ) ) {
		$body = array( 'matches' => array(
			array( 'id' => 5001, 'utcDate' => tipc_test_iso( -60 ), 'status' => 'FINISHED', 'matchday' => 9,
				'homeTeam' => array( 'name' => 'Arsenal FC', 'shortName' => 'Arsenal', 'crest' => 'https://crests.football-data.org/57.png' ),
				'awayTeam' => array( 'name' => 'Chelsea FC', 'shortName' => 'Chelsea', 'crest' => 'https://crests.football-data.org/61.png' ),
				'score' => array( 'winner' => 'DRAW', 'fullTime' => array( 'home' => 1, 'away' => 1 ) ) ),
			array( 'id' => 5002, 'utcDate' => tipc_test_iso( -58 ), 'status' => 'FINISHED', 'matchday' => 9,
				'homeTeam' => array( 'shortName' => 'Liverpool' ), 'awayTeam' => array( 'shortName' => 'Everton' ),
				'score' => array( 'winner' => 'HOME_TEAM', 'fullTime' => array( 'home' => 3, 'away' => 0 ) ) ),
			array( 'id' => 5003, 'utcDate' => tipc_test_iso( 100 ), 'status' => 'TIMED', 'matchday' => 10,
				'homeTeam' => array( 'shortName' => 'Man City' ), 'awayTeam' => array( 'shortName' => 'Spurs' ),
				'score' => array( 'winner' => null, 'fullTime' => array( 'home' => null, 'away' => null ) ) ),
		) );
	} elseif ( false !== strpos( $url, 'thesportsdb.com' ) ) {
		$body = array( 'events' => array(
			array( 'idEvent' => '9001', 'intRound' => '1', 'strHomeTeam' => 'Melbourne Storm', 'strAwayTeam' => 'Penrith Panthers',
				'intHomeScore' => '18', 'intAwayScore' => '20', 'strTimestamp' => gmdate( 'Y-m-d\TH:i:s', time() - 30 * 3600 ), 'strStatus' => 'Match Finished', 'strVenue' => 'AAMI Park' ),
			array( 'idEvent' => '9002', 'intRound' => '2', 'strHomeTeam' => 'Penrith Panthers', 'strAwayTeam' => 'Melbourne Storm',
				'intHomeScore' => null, 'intAwayScore' => null, 'strTimestamp' => gmdate( 'Y-m-d\TH:i:s', time() + 30 * 3600 ), 'strStatus' => 'Not Started' ),
		) );
	}

	if ( null === $body ) {
		return new WP_Error( 'http_request_failed', 'Mock: no route for ' . $url );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );
