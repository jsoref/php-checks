<?php

require __DIR__ . '/../vendor/autoload.php';
require_once '../config.php';
require_once '../util.php';

$payload = payload();

switch ($_SERVER["HTTP_X_GITHUB_EVENT"]) {
	case "ping":
	echo "Hello GitHub!";
	break;
	case "check_run":
	if ($payload['check_run']['check_suite']['app']['id'] !== $app_id[which_github()]) {
		echo "App ID is ".$payload['check_run']['check_suite']['app']['id']." not ".$app_id[which_github()].", ignoring";
		exit();
	}
	if ($payload['action'] !== 'created' && $payload['action'] !== 'rerequested') {
		echo "Action is ".$payload['action'].", ignoring";
		exit();
	}
	$token = token();
	github(
		$payload['check_run']['url'],
		[
			'status' => 'in_progress',
			'started_at'=>date(DATE_ATOM)
		],
		"reporting check_run in progress",
		"application/vnd.github.antiope-preview+json",
		"PATCH",
		200
	);
	$log_location = __DIR__."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha']."/".$payload['check_run']['external_id'];
	mkdir($log_location, 0700, true);
	copy(__DIR__."/../log-index.html", $log_location."/index.html");
	copy(__DIR__."/../worker.js", $log_location."/worker.js");
	$return_value = 0;
	passthru('/bin/bash -e -o pipefail '.__DIR__.'/../'.$payload['check_run']['external_id'].'.sh '.$payload["repository"]["name"].' > '.$log_location.'/plain.txt 2>&1', $return_value);
	if ($return_value !== 0 && $payload['check_run']['external_id'] !== "phpstan" && $payload['check_run']['external_id'] !== "phan") {
		echo "check_run failed with return value ".$return_value.", see output above.";
		github(
			$payload['check_run']['url'],
			[
				'conclusion' => 'action_required',
				'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/'.$payload['check_run']['external_id'].'/plain.txt',
			],
			"reporting check_run failure",
			"application/vnd.github.antiope-preview+json",
			"PATCH",
			200
		);
		exit();
	}
	switch ($payload['check_run']['external_id']) {
		case "syntax":
		$syntax_log = explode("\n",file_get_contents($log_location."/plain.txt"));
		$files_with_issues = 0;
		$issues = 0;
		$annotations = [];
		for ($i = 0; $i < count($syntax_log); $i++) {
			if (strpos($syntax_log[$i], "No syntax errors detected in ") !== FALSE) {
				continue;
			} elseif (strpos($syntax_log[$i], "Errors parsing ") !== FALSE) {
				$files_with_issues += 1;
				continue;
			} elseif (strpos($syntax_log[$i], "PHP Parse error:  syntax error, ") !== FALSE) {
				$matches = [];
				if (1 !== preg_match("/in (.+) on line ([[:digit:]]+)$/", $syntax_log[$i], $matches, PREG_OFFSET_CAPTURE)) {
					echo "Could not parse output from PHP syntax check ".$syntax_log[$i];
					github(
						$payload['check_run']['url'],
						[
							'conclusion' => 'action_required',
							'completed_at'=>date(DATE_ATOM)
						],
						"reporting check_run failure",
						"application/vnd.github.antiope-preview+json",
						"PATCH",
						200
					);
					exit();
				}
				$issues += 1;
				$annotations[] = ['path'=>substr($matches[1][0], 2, strlen($matches[1][0])),'start_line'=>intval($matches[2][0]),'end_line'=>intval($matches[2][0]),'annotation_level'=>'failure','message'=>substr($syntax_log[$i],32, $matches[0][1] - 33)];
			} elseif (strlen($syntax_log[$i]) === 0) {
				continue;
			} else {
				echo "Unexpected output from PHP syntax check: ".$syntax_log[$i];
				github(
					$payload['check_run']['url'],
					[
						'conclusion' => 'action_required',
						'completed_at'=>date(DATE_ATOM)
					],
					"reporting check_run failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
				exit();
			}
		}
		if ($issues == 0) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion'=>'success',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/syntax/plain.txt',
					'output' => [
						'title' => 'All files successfully parsed',
						'summary' => "All PHP files in the repository were successfully parsed.",
					]
				],
				"reporting syntax check success",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
				);
		} else {
			$total_annotations = count($annotations);
			$chunks = array_chunk($annotations, 50);
			for ($i = 0; $i < ($total_annotations / 50); $i++) {
				github(
					$payload['check_run']['url'],
					[
						'conclusion'=>'failure',
						'completed_at'=>date(DATE_ATOM),
						'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/syntax/plain.txt',
						'output' => [
							'title' => 'Found '.$issues.' issue'.( $issues === 1 ? '' : 's').' in '.$files_with_issues.' file'.( $files_with_issues === 1 ? '' : 's' ),
							'summary' => "PHP was unable to parse the below file".( $files_with_issues === 1 ? '' : 's' ).".",
							'annotations' => $chunks[$i]
						]
					],
					"reporting syntax check failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
			}
		}
		break;
		case 'codesniffer':
		$log = json_decode(file_get_contents($log_location."/plain.txt"), true);
		$files_with_issues = 0;
		$issues = 0;
		$annotations = [];
		$phpcs_to_github = [];
		$phpcs_to_github["ERROR"] = "failure";
		$phpcs_to_github["WARNING"] = "warning";
		foreach ($log['files'] as $path => $file) {
			foreach ($file['messages'] as $message) {
				$issues++;
				$annotations[] = [
					'path' => substr($path, 21 + strlen($payload["repository"]["name"]), strlen($path)),
					'start_line'=>$message['line'],
					'end_line'=>$message['line'],
					'annotation_level'=>$phpcs_to_github[$message['type']],
					'message'=>$message['message'],
				];
			}
			if (count($file['messages']) > 0) {
				$files_with_issues++;
			}
		}
		if ($issues == 0) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion'=>'success',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/codesniffer/plain.txt',
					'output' => [
						'title' => 'All files meet code style requirements',
						'summary' => "All PHP files in the repository comply with the PSR-2 style guide.",
					]
				],
				"reporting codesniffer check success",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
		} else {
			$total_annotations = count($annotations);
			$chunks = array_chunk($annotations, 50);
			for ($i = 0; $i < ($total_annotations / 50); $i++) {
				github(
					$payload['check_run']['url'],
					[
						'conclusion'=>'failure',
						'completed_at'=>date(DATE_ATOM),
						'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/codesniffer/plain.txt',
						'output' => [
							'title' => 'Found '.$issues.' issue'.( $issues === 1 ? '' : 's').' in '.$files_with_issues.' file'.( $files_with_issues === 1 ? '' : 's' ),
							'summary' => "The below file".( $files_with_issues === 1 ? '' : 's' )." do".( $files_with_issues === 1 ? 'es' : '' )." not comply with the PSR-2 style standard.",
							'annotations' => $chunks[$i]
						]
					],
					"reporting codesniffer check failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
			}
		}
		break;
		case 'messdetector':
		$xml = simplexml_load_file($log_location."/plain.txt");
		if ($xml === FALSE) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion' => 'action_required',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/messdetector/plain.txt',
				],
				"reporting check_run failure",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
			exit();
		}

		$files_with_issues = 0;
		$issues = 0;
		$annotations = [];

		foreach ($xml->children() as $file) {
			$files_with_issues++;
			foreach ($file->children() as $violation) {
				$issues++;
				$annotations[] = [
					'path' => substr($file['name']->__toString(), 21 + strlen($payload["repository"]["name"]), strlen($file['name']->__toString())),
					'start_line'=>intval($violation['beginline']->__toString()),
					'end_line'=>intval($violation['endline']->__toString()),
					'annotation_level'=>"failure",
					'message'=>trim($violation->__toString()),
				];
			}
		}
		if ($issues == 0) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion'=>'success',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/messdetector/plain.txt',
					'output' => [
						'title' => 'No messes detected',
						'summary' => "Mess Detector did not detect any messes.",
					]
				],
				"reporting mess detector check success",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
		} else {
			$total_annotations = count($annotations);
			$chunks = array_chunk($annotations, 50);
			for ($i = 0; $i < ($total_annotations / 50); $i++) {
				github(
					$payload['check_run']['url'],
					[
						'conclusion'=>'failure',
						'completed_at'=>date(DATE_ATOM),
						'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/messdetector/plain.txt',
						'output' => [
							'title' => 'Found '.$issues.' issue'.( $issues === 1 ? '' : 's').' in '.$files_with_issues.' file'.( $files_with_issues === 1 ? '' : 's' ),
							'summary' => ( $issues === 1 ? 'A mess was' : 'Messes were' )." detected in the below file".( $files_with_issues === 1 ? '' : 's' ).".",
							'annotations' => $chunks[$i]
						]
					],
					"reporting messdetector check failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
			}
		}
		break;
		case 'phpstan':
		$xml = simplexml_load_file($log_location."/plain.txt");
		if ($xml === FALSE) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion' => 'action_required',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/phpstan/plain.txt',
				],
				"reporting check_run failure",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
			exit();
		}

		$files_with_issues = 0;
		$issues = 0;
		$annotations = [];
		$phpstan_to_github = [];
		$phpstan_to_github["error"] = "failure";

		foreach ($xml->children() as $file) {
			$files_with_issues++;
			foreach ($file->children() as $violation) {
				$issues++;
				$annotations[] = [
					'path' => $file['name']->__toString(),
					'start_line'=>intval($violation['line']->__toString()),
					'end_line'=>intval($violation['line']->__toString()),
					'annotation_level'=>$phpstan_to_github[$violation['severity']->__toString()],
					'message'=>trim($violation['message']->__toString()),
				];
			}
		}
		if ($issues == 0) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion'=>'success',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/phpstan/plain.txt',
					'output' => [
						'title' => 'No issues found',
						'summary' => "PHPStan did not find any issues.",
					]
				],
				"reporting phpstan check success",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
		} else {
			$total_annotations = count($annotations);
			$chunks = array_chunk($annotations, 50);
			for ($i = 0; $i < ($total_annotations / 50); $i++) {
				github(
					$payload['check_run']['url'],
					[
						'conclusion'=>'failure',
						'completed_at'=>date(DATE_ATOM),
						'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/phpstan/plain.txt',
						'output' => [
							'title' => 'Found '.$issues.' issue'.( $issues === 1 ? '' : 's').' in '.$files_with_issues.' file'.( $files_with_issues === 1 ? '' : 's' ),
							'summary' => ( $issues === 1 ? 'An issue was' : 'Issues were' )." found in the below file".( $files_with_issues === 1 ? '' : 's' ).".",
							'annotations' => $chunks[$i]
						]
					],
					"reporting phpstan check failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
			}
		}
		break;
		case 'phan':
		$log = json_decode(file_get_contents($log_location."/plain.txt"), true);
		$files_with_issues = 0;
		$files = [];
		$issues = 0;
		$annotations = [];
		foreach ($log as $message) {
			$issues++;
			$annotations[] = [
				'path' => substr($message['location']['path'], 21 + strlen($payload["repository"]["name"]), strlen($message['location']['path'])),
				'start_line'=>$message['location']['lines']['begin'],
				'end_line'=>$message['location']['lines']['end'],
				'annotation_level'=>"failure",
				'message'=>str_replace("/var/tmp/php-checks/".$payload["repository"]["name"], "", $message['description']),
			];
			if (!in_array($message['location']['path'], $files)) {
				$files[] = $message['location']['path'];
			}
		}
		$files_with_issues = count($files);
		if ($issues == 0) {
			github(
				$payload['check_run']['url'],
				[
					'conclusion'=>'success',
					'completed_at'=>date(DATE_ATOM),
					'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/codesniffer/plain.txt',
					'output' => [
						'title' => 'All files meet code style requirements',
						'summary' => "All PHP files in the repository comply with the PSR-2 style guide.",
					]
				],
				"reporting codesniffer check success",
				"application/vnd.github.antiope-preview+json",
				"PATCH",
				200
			);
		} else {
			$total_annotations = count($annotations);
			$chunks = array_chunk($annotations, 50);
			for ($i = 0; $i < ($total_annotations / 50); $i++) {
				github(
					$payload['check_run']['url'],
					[
						'conclusion'=>'failure',
						'completed_at'=>date(DATE_ATOM),
						'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_run']['head_sha'].'/codesniffer/plain.txt',
						'output' => [
							'title' => 'Found '.$issues.' issue'.( $issues === 1 ? '' : 's').' in '.$files_with_issues.' file'.( $files_with_issues === 1 ? '' : 's' ),
							'summary' => ( $issues === 1 ? 'An issue was' : 'Issues were' )." found in the below file".( $files_with_issues === 1 ? '' : 's' ).".",
							'annotations' => $chunks[$i]
						]
					],
					"reporting codesniffer check failure",
					"application/vnd.github.antiope-preview+json",
					"PATCH",
					200
				);
			}
		}
		break;
	}
	break;
	case "check_suite":
	if ($payload['action'] !== 'requested' && $payload['action'] !== 'rerequested') {
		echo "Action is ".$payload['action'].", ignoring";
		exit();
	}
	$token = token();
	$return_value = 0;
	passthru('/bin/bash -x -e -o pipefail '.__DIR__.'/../checkout.sh '.$payload["repository"]["name"].' '.add_access_token($payload["repository"]["clone_url"]).' '.$payload['check_suite']['head_sha'], $return_value);
	if ($return_value !== 0) {
		echo "Checkout failed with return value ".$return_value.", see output above.";
		exit();
	}
	github(
		$payload['repository']['url'].'/check-runs',
		[
			'name' => 'Syntax',
			'head_sha' => $payload['check_suite']['head_sha'],
			'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_suite']['head_sha'].'/syntax/',
			'external_id' => "syntax",
		],
		'creating check run for syntax',
		'application/vnd.github.antiope-preview+json'
	);
	github(
		$payload['repository']['url'].'/check-runs',
		[
			'name' => 'CodeSniffer',
			'head_sha' => $payload['check_suite']['head_sha'],
			'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_suite']['head_sha'].'/codesniffer/',
			'external_id' => "codesniffer",
		],
		'creating check run for codesniffer',
		'application/vnd.github.antiope-preview+json'
	);
	github(
		$payload['repository']['url'].'/check-runs',
		[
			'name' => 'Mess Detector',
			'head_sha' => $payload['check_suite']['head_sha'],
			'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_suite']['head_sha'].'/messdetector/',
			'external_id' => "messdetector",
		],
		'creating check run for messdetector',
		'application/vnd.github.antiope-preview+json'
	);
	github(
		$payload['repository']['url'].'/check-runs',
		[
			'name' => 'PHPStan',
			'head_sha' => $payload['check_suite']['head_sha'],
			'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_suite']['head_sha'].'/phpstan/',
			'external_id' => "phpstan",
		],
		'creating check run for phpstan',
		'application/vnd.github.antiope-preview+json'
	);
	github(
		$payload['repository']['url'].'/check-runs',
		[
			'name' => 'Phan',
			'head_sha' => $payload['check_suite']['head_sha'],
			'details_url' => "https://".$_SERVER["SERVER_NAME"]."/".$payload["repository"]["name"]."/".$payload['check_suite']['head_sha'].'/phan/',
			'external_id' => "phan",
		],
		'creating check run for phan',
		'application/vnd.github.antiope-preview+json'
	);
	break;
	default:
	echo "Unrecognized event ".$_SERVER["HTTP_X_GITHUB_EVENT"];
	break;
}