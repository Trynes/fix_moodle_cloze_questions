<?php
// This file is FOR Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script fixes corrupt cloze questions...
 *
 * @package
 * @subpackage cli
 * @copyright  2018 Rebecca Trynes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);
define('CLI_SCRIPT', true);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/questionlib.php');
require_once($CFG->dirroot.'/question/engine/bank.php');
require_once($CFG->dirroot.'/question/engine/lib.php');

// Get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'questions'         => false,
		'info'				=> false,
		'fix'               => false,
		'verbose'			=> false,
        'help'              => false
    ),
    array(
        'h' => 'help',
        'q' => 'questions',
		'i' => 'info',
		'v' => 'verbose',
        'f' => 'fix'
    )
);

//$DB->set_debug(true);

//hostname using the inbuilt moodle function was returning 'unknownhost'
//I don't think it really matters if it's not host specific...
function make_unique_code($extra = '') {
    $hostname = 'admin.cli.script.';
    $date = gmdate("ymdHis");
    $random =  random_string(6);
    if ($extra) {
        return $hostname .'+'. $date .'+'. $random .'+'. $extra;
    } else {
        return $hostname .'+'. $date .'+'. $random;
    }
}

/*------------------------------------------------------------------
*  Take any text and turn it into plain text without HTML or spaces
*   but keep any image filenames that might be in it...
*   (this makes searching for questiontext and comparing easier!)
-------------------------------------------------------------------*/

function plainText($text) {
	//Turn the text into plain text (easier to read!)
	//If there's a title in a p tag, remove it...
	$plaintext = preg_replace('/<p title="(.*?)">/', '<p>', $text);
	//If there are p tags butting up against each other, separate them with a space...
	$plaintext = preg_replace('/<\/p><p>/', '<\/p> <p>', $plaintext);
	//Strip ALL html tags EXCEPT for img tags...
	$plaintext = strip_tags($plaintext, '<img>');
	//Get the image source filename...
	if(preg_match('%<img\s.*?src=".*?/?([^/]+?(\.gif|\.png|\.jpg))"%s', $plaintext, $regs)) {
		$image = $regs[1];
	} else {
		$image = '';
	}
	//Find the img code...
	$plaintext = preg_replace('(<img.*?src="(.*?)".*?(>|/>|</img>))', '{{{img: '.$image.'}}} ', $plaintext);
		//If there are any non-breaking space, remove them...
	$plaintext = preg_replace('/&nbsp;/', ' ', $plaintext);
		//If there are blank spaces more than one space, make it one...
	//the u-parameter is if the string is UTF-8 encoded!!!!
	//(Meaning it can have a whitespace that isn't covered with just /\s+/)
	$plaintext = preg_replace('/\s+/u', ' ', $plaintext);
	$plaintext = str_replace('\n', ' ', $plaintext);
	$plaintext = str_replace('\r', '', $plaintext);
	$plaintext = preg_replace('([^0-9a-zA-Z{}:/$%#]+)', '', $plaintext);


	return $plaintext;
}

//This one removes the img filename so you can compare JUST questiontext.
function noimgText($text) {
	$noimgtext = preg_replace('(({{{img.*?(png|PNG|jpg|JPG|gif|GIF)}}}))', '', $text);

	return $noimgtext;
}

/*-----------------------------------------------------------------------
*  Query the database to find questions in ANY course, or course-specific
------------------------------------------------------------------------*/
$checklist     = preg_split('/\s*,\s*/', $options['questions'], -1, PREG_SPLIT_NO_EMPTY);

//if * is in the questionlist (meaning all questions)
if (in_array('*', $checklist)) {

	//Only want the multianswer questions...
	$where = "WHERE qtype = 'multianswer' ORDER BY questiontext";
    $params = array(); //No params

	$GLOBALS["where"] = $where;
	$GLOBALS["params"] = $params;

} else {//The user has specified a question to check
	if($options['questions']) {
		//This will only check to see if the specified question is in qma.question column
		//Not the qma.sequence column, so it will only show if it's a base cloze question...
		list($sql, $params) = $DB->get_in_or_equal($checklist, SQL_PARAMS_NAMED, 'question');
		//Where question is in or equal to the 'question' specified in the questionlist.
		$where = 'WHERE question '. $sql;

		$GLOBALS["where"] = $where;
	}
}

//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
// This gets the sequence info for a question:
// * The sequence
// * Are the number of sequence questions equal to the amount of placeholders in the question?
// * Do all of the sequence questions exist in the database?
// * Is the parent of all the sequence questions the same as the originating question?
//   -- if not, it means it's a faulty sequence and needs to be fixed!
//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
function get_sequence_info($question=null,$placeholderCount) {
	global $DB;

	$data = array();

	$record   = $DB->get_record_sql("SELECT sequence FROM {question_multianswer} WHERE question = '$question'");
	$sequence = $record->sequence;

	$data['sequence'] = $sequence;

	$seq_usage = $DB->get_record_sql("SELECT COUNT(sequence) as usage FROM {question_multianswer} WHERE sequence = '$sequence'");
	$usage = $seq_usage->usage;

	$data['usage'] = $usage;

	$questions = explode(',', $sequence);
	$sqcount   = count($questions);

	$data['seq_questions'] = $questions;

	//If the sequence = 0, it's the same as not being equal really....
	if($sqcount !== $placeholderCount || $sequence === '0') {
		$data['equality'] = false;
	} else {
		$data['equality'] = true;
	}

	//---------------------------------------------------
	// Do all of the sequence questions exist in the DB?
	// Check the exploded list $sqs...

	$sqs_exist = array();
	$sqs_missing = array();

	foreach ($questions as $check) {
		if($check != '') {
		if($sequence !== '0') {

			$exists = $DB->get_record('question', array('id'=>$check));

			if (!empty($exists)) {

				//---------------------------------------------------
				//Positive result? Add it to the sqs_exists array...
				$sqs_exist[] = $check;

				//---------------------------------------------------
				//What is the parent for this sequence question?
				//Is it the same as the base question that we're checking?
				$parent = $exists->parent;

				$data['parent']['id'][] = $parent;

				if($parent !== $question) {
					$data['parent']['wrong'][]   = $check;
				} else {
					$data['parent']['correct'][] = $check;
				}

				//---------------------------------------------------
				//Do any other sequences share this sequence question?

				//Have to be specific when it comes to the length of the question to check!
				//If it's LIKE '%$check%', then it might come up with a totally different question.
				//e.g. 11532 could show 111532 (note the extra 1!)
				$othersequence = $DB->get_records_sql("SELECT question,sequence FROM {question_multianswer}
													   WHERE (sequence LIKE '%,$check,%'
														   OR sequence LIKE '%,$check'
														   OR sequence LIKE '$check,%')
														  AND sequence != '$record->sequence'");
				//var_dump($othersequence);
				if(!empty($othersequence)) {
					$data['otherseq'][$check] = $othersequence;
				} else {
					$data['otherseq'][$check] = false;
				}

			} else {
				$sqs_missing[] = $check;
			}
		}}
		$data['sqs_exist']   = $sqs_exist;
		$data['sqs_missing'] = $sqs_missing;
	}

	return $data;
}

//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
// This gets the info about a sequence or questions in a course....
//
// I made this not echo stuff out as it does in return_info so that it can be used in other
//  functions for data gathering purposes. This might not be the best way to do it, however.
//  Downfall of being a novice programmer/developer/whatever...
//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
function get_info($text=null,$group=null,$params=null) {
	global $DB;
	//var_dump($text);

	//questiontext(array) => sequence(array) => questions(array)

	//------------------------------------------------------------------------------------------
	//Go through each of the sequence groups and get all the infos for the questions:

	//mdl_question:
	//question ids, questiontext, number of cloze placeholder questions in the question text (qc_count)

	//mdl_question_multianswer:
	//sequence, sequence length/count (sqs_length), any missing sequence questions?: [sqs_missing, sqs_exist]
	//duplicate id's, parent info of sequence questions: [id, name, question text], duplicate count,
	//similar sequences, other question ids with this similar sequence, number of these other qs,

	//mdl_question_categories:
	//category id, category name

	//mdl_question_attempts:
	//attempted or not, quiz name
	//attempted info: [courseid, course name, quiz name]

	//mdl_course:
	//courseid, course shortname,
	//coursecount (no. of courses affected)

	//-----------------------------------------------------------------------------------------

	$data = array();

	//-----------------------------------------------------------------------------------------

	$plaintext	= $text;
	$noimgtext	= noimgText($plaintext);

	$data['plaintext'] = $plaintext;
	//$data['noimgtext'] = $noimgtext;

	//---------------------------------------------------
	// The amount of placeholders in this question are....
	// RT: Had to change the match from '0 or more' to '1 or more' as it could return a false positive...
	$placeholders 	   = preg_match_all('({#[0-9]+})', $text, $result, PREG_PATTERN_ORDER);
	//var_dump($result);
	$result 		   = $result[0];
	$placeholderCount  = count($result);

	$data['qc_count'] = $placeholderCount;

	//---------------------------------------------------
	// The question ids for each individual questiontext....

	$questionids = array();

	foreach ($group as $sequence => $questions) {

		$sequences[] = (string)$sequence;

		foreach($questions as $question) {
			$questionids[] = $question->question;
		}
	}
	//var_dump($questionids);

	$sequencecount = count($sequences);
	$questioncount = count($questionids);

	$data['allquestionids'] 	 = $questionids;
	$data['totalquestioncount']  = $questioncount;

	$data['allsequences'] 		 = $sequences;
	$data['totalsequencecount']  = $sequencecount;

	//------------------------------------------------------
	// Now find out all the info related to these sequence groups:

	foreach ($group as $sequence => $questions) {
		foreach($questions as $q) {

		$question 	  = $q->question;

		$questioninfo = $DB->get_record_sql("SELECT * FROM {question} WHERE id = '$question'");
		//var_dump($questioninfo);
		// Group by attempted/not attempted.

		//   If they have been attempted and have an equality count of false,
		//   it's best not to mess with them until you've had a look at
		//   the 'right answer' to see how many sequence questions it had when attempted.


		//---------------------------------------------------
		//Have any of these questions been attempted?

		$attempts = $DB->get_records_sql("SELECT id FROM {question_attempts} WHERE questionid = '$question'");

		//var_dump($attempts);
		if (!empty($attempts)) {

			//-----------------------------------------------------------------------------------------------------
			//The stuff below should be a function, but I'm struggling to get the proper output on the other end!!!
			//-----------------------------------------------------------------------------------------------------

			$data[$sequence]['attempted_questions'][$question]['question']['id']   = $question;
			$data[$sequence]['attempted_questions'][$question]['question']['name'] = $questioninfo->name;

			//---------------------------------------------------
			//What is the sequence for this question?

			$sequenceinfo = get_sequence_info($question,$placeholderCount);
			//var_dump($sequence);

			foreach($sequenceinfo as $key => $info) {
				//var_dump($key);
				//var_dump($info);
				$data[$sequence]['attempted_questions'][$question]['sequence'][$key] = $info;
			}

			//---------------------------------------------------
			// What is the course/quiz that this attempted question appears in?

			//Sometimes the category the question is in is at the quiz context level (70), not the course level.
			$quizcontext = $DB->get_records_sql("
							SELECT q.category as categoryid,qc.name as category,cxt.contextlevel,cxt.path
							FROM {question} q
							JOIN {question_categories} qc ON q.category = qc.id
							JOIN {context} cxt ON qc.contextid = cxt.id
							WHERE q.id = $question AND cxt.contextlevel = 70");
			//var_dump($quizcontext);

			$contexts = array();
			foreach($quizcontext as $qc) {
				$contexts[] = explode('/', $qc->path);
			}
			//var_dump($contexts);

			$coursecontext = array();
			foreach($contexts as $context) {
				//var_dump($context);
				//If there are 4 paths (0 is empty), take the 3rd value as the
				//context we need....
				if(count($context) == 5) {
					$coursecontext[] = $context[3];
				} else if(count($context) == 6) {
					$coursecontext[] = $context[4];
				}
			}

			//If the question is in a category at the quiz context level...
			if(!empty($coursecontext)) {
				foreach($coursecontext as $ccxt) {
					$course = $DB->get_records_sql("
								SELECT cxt.id,c.id as courseid,c.shortname
								FROM {context} cxt
								JOIN {course} c ON c.id = cxt.instanceid
								WHERE cxt.id = $ccxt");
				}
			} else {
				//otherwise, it's at the course context level...
				$course = $DB->get_records_sql("
							SELECT q.category as categoryid,qc.name as category,c.id as courseid,c.shortname
							FROM {question} q
							JOIN {question_categories} qc ON q.category = qc.id
							JOIN {context} cxt ON qc.contextid = cxt.id
							JOIN {course} c ON c.id = cxt.instanceid
							WHERE q.id = $question");
			}

			//var_dump($course);

			if(!empty($course)) {
			foreach($course as $c) {
				$data[$sequence]['attempted_questions'][$question]['course']['id']   	  = $c->courseid;
				$data[$sequence]['attempted_questions'][$question]['course']['name'] 	  = $c->shortname;
				if(!empty($c->categoryid)) {
				$data[$sequence]['attempted_questions'][$question]['q_category']['id']    = $c->categoryid;
				$data[$sequence]['attempted_questions'][$question]['q_category']['name']  = $c->category;
				}
			}}

			//If it was found through the quiz context, not through the question context
			//we need the question category from that...
			if(!empty($quizcontext)) {
			foreach($quizcontext as $q) {
				if(!empty($q->categoryid)) {
					$data[$sequence]['attempted_questions'][$question]['q_category']['id']    = $q->categoryid;
					$data[$sequence]['attempted_questions'][$question]['q_category']['name']  = $q->category;
				}
			}}

			//Get attempted quiz info...
			$quiz = $DB->get_records_sql("
							SELECT qs.quizid,qz.name
							FROM {quiz_slots} qs
							JOIN {quiz} qz ON qs.quizid = qz.id
							WHERE questionid = '$question'");

			//var_dump($quiz);

			if(!empty($quiz)) {
			foreach($quiz as $q) {
				$data[$sequence]['attempted_questions'][$question]['quiz']['id'] 	= $q->quizid;
				$data[$sequence]['attempted_questions'][$question]['quiz']['name']  = $q->name;
			}}
		} else {
			//---------------------------------------------------
			//---------------------------------------------------
			// Non-attempted question info......
			//---------------------------------------------------
			//---------------------------------------------------

			//---------------------------------------------------
			// Question id and name....

			$data[$sequence]['na_questions'][$question]['question']['id']   = $question;
			$data[$sequence]['na_questions'][$question]['question']['name'] = $questioninfo->name;


			//---------------------------------------------------
			//What is the sequence for this question?

			$sequenceinfo = get_sequence_info($question,$placeholderCount);
			//var_dump($sequence);

			foreach($sequenceinfo as $key => $info) {
				//var_dump($key);
				//var_dump($info);
				$data[$sequence]['na_questions'][$question]['sequence'][$key] = $info;
			}


			//---------------------------------------------------
			// What is the course/quiz that this attempted question appears in?

			//Sometimes the category the question is in is at the quiz context level (70), not the course level.
			$quizcontext = $DB->get_records_sql("
							SELECT q.category as categoryid,qc.name as category,cxt.contextlevel,cxt.path
							FROM {question} q
							JOIN {question_categories} qc ON q.category = qc.id
							JOIN {context} cxt ON qc.contextid = cxt.id
							WHERE q.id = $question AND cxt.contextlevel = 70");
			//var_dump($quizcontext);

			$contexts = array();
			foreach($quizcontext as $qc) {
				$contexts[] = explode('/', $qc->path);
			}
			//var_dump($contexts);

			$coursecontext = array();
			foreach($contexts as $context) {
				//var_dump($context);
				//If there are 4 paths (0 is empty), take the 3rd value as the
				//context we need....
				if(count($context) == 5) {
					$coursecontext[] = $context[3];
				} else if(count($context) == 6) {
					$coursecontext[] = $context[4];
				}
			}

			//var_dump($coursecontext);
			//If the question is in a category at the quiz context level...
			if(!empty($coursecontext)) {
				foreach($coursecontext as $ccxt) {
					$course = $DB->get_records_sql("
								SELECT cxt.id,c.id as courseid,c.shortname
								FROM {context} cxt
								JOIN {course} c ON c.id = cxt.instanceid
								WHERE cxt.id = $ccxt");
				}
			} else {
				//otherwise, it's at the course context level...
				$course = $DB->get_records_sql("
							SELECT q.category as categoryid,qc.name as category,c.id as courseid,c.shortname
							FROM {question} q
							JOIN {question_categories} qc ON q.category = qc.id
							JOIN {context} cxt ON qc.contextid = cxt.id
							JOIN {course} c ON c.id = cxt.instanceid
							WHERE q.id = $question");
			}
			//var_dump($course);

			if(!empty($course)) {
			foreach($course as $c) {
				$data[$sequence]['na_questions'][$question]['course']['id']   	   = $c->courseid;
				$data[$sequence]['na_questions'][$question]['course']['name'] 	   = $c->shortname;
				if(!empty($c->categoryid)) {
				$data[$sequence]['na_questions'][$question]['q_category']['id']    = $c->categoryid;
				$data[$sequence]['na_questions'][$question]['q_category']['name']  = $c->category;
				}
			}}

			//If it was found through the quiz context, not through the question context
			//we need the question category from that...
			if(!empty($quizcontext)) {
			foreach($quizcontext as $q) {
				if(!empty($q->categoryid)) {
					$data[$sequence]['na_questions'][$question]['q_category']['id']    = $q->categoryid;
					$data[$sequence]['na_questions'][$question]['q_category']['name']  = $q->category;
				}
			}}

			//---------------------------------------------------
			//Do any of these questions appear in a quiz, even if they haven't been attempted?

			$used = $DB->get_records_sql("SELECT qs.quizid,qz.name FROM {quiz_slots} qs JOIN {quiz} qz ON qs.quizid = qz.id WHERE questionid = '$question'");
			//var_dump($used);
			if (!empty($used)) {
				foreach($used as $info) {
					$data[$sequence]['na_questions'][$question]['quiz']['id'] 	 = $info->quizid;
					$data[$sequence]['na_questions'][$question]['quiz']['name']  = $info->name;
				}
			}
		}
	}
	}

	return $data;
}

//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
// This returns the info for the questions from get_info.
// If you have specified 'verbose', it will show ALL questions,
// otherwise, you'll just get the questiontext that contains broken ones...
//-----------------------------------------------------------------------------------------------
//-----------------------------------------------------------------------------------------------
function return_info($getinfo,$x,$options) {
	//var_dump($getinfo);

	//Set up the verbosity of the result:

	//If there's no course name or category (it's an orphaned question),
	//Or it has the wrong parent, the sequence is '0',
	//no sequence questions exist in the DB, the usage is over 1,
	//or the equality is not true,
	//Put these into the 'broken' array.

	//Otherwise, they're all fine for this questiontext
	//and can be skipped if not using the 'verbose' option.
	$broken = array();

	foreach($getinfo['allsequences'] as $sequence) {
	foreach($getinfo[$sequence] as $attempt_status => $sequenceinfo) {
	foreach($sequenceinfo as $infos) {
		//var_dump($infos);

		if(
			empty($infos['course']['name']) ||
			empty($infos['q_category']['name']) ||
			($infos['sequence']['sequence'] === '0') ||
			($infos['sequence']['usage'] > 1) ||
			empty($infos['sequence']['parent']['correct']) ||
			($infos['sequence']['equality'] !== true) ||
			( empty($infos['sequence']['sqs_exist']) && !empty($infos['sequence']['sqs_missing']) )
		) {
			$broken[] = $getinfo;
		}

	}}}

	//If you've asked for the good and the bad, return everything...
	if( $options['verbose'] ) {
		$result = $getinfo;
	} else {
		//You've asked for just the results with broken questions...
		if(!empty($broken)) {
			$result = $getinfo;
		} else {
			$result = NULL;
		}
	}

	if($result !== NULL) {

	$info  ='<div class="clearer"></div>'."\n";
	$info .= "<-- Next Question -->\n";
	$info .='<div class="box">';

	$info.='<h1 style="text-align: center;">------------------------- Question info ---------------------------</h1>'
		 ."\n";

	$info .="<p><b>[ The stripped question text is ]:</b></p>";
	$info .='<p style="word-wrap:break-word">'.$result['plaintext']."</p>\n\n";

	$info .="<hr>\n\n";

	$info .="<p><b>[     Placeholders ]:</b> ".$result['qc_count']."</p>\n\n";

	$info .="<p><b>[ No. of questions ]:</b> ".$result['totalquestioncount']."</p>\n\n";

	$info .="<hr>";

	$info .="</div>";

	$info .='<br>';

	$info .='<table class="table'.$x.'"><tbody>';
	$info .='<tr>';
	$info .='<th scope="col">Q id</th>';
	$info .='<th scope="col">Q name</th>';
	$info .='<th scope="col">course</th>';
	$info .='<th scope="col">Q category</th>';
	$info .='<th scope="col">quiz</th>';
	$info .='<th scope="col">attempted</th>';
	$info .='<th scope="col">sequence</th>';
	$info .='<th scope="col">parent Q</th>';
	$info .='<th scope="col">usage</th>';
	$info .='<th scope="col">equality</th>';
	$info .='<th scope="col">all Qs exist?</th>';
	$info .='<th scope="col">similar sequence</th>';
	$info .='</tr>'."\n";

	//Add a button to show/hide good results for this table...
	$info .='<button class="table'.$x.'">Hide/Show Good Rows</button>';

	foreach($result['allsequences'] as $sequence) {

		foreach($result[$sequence] as $attempt_status => $sequenceinfo) {
			//var_dump($attempt_status);
			//var_dump($sequenceinfo);

		foreach($sequenceinfo as $infos) {
			//var_dump($infos);

			//Give the rows a certain class if they have dodgy rows or not...
			if(
				empty($infos['course']['name']) ||
				empty($infos['q_category']['name']) ||
				($infos['sequence']['sequence'] === '0') ||
				($infos['sequence']['usage'] > 1) ||
				empty($infos['sequence']['parent']['correct']) ||
				($infos['sequence']['equality'] !== true) ||
				( empty($infos['sequence']['sqs_exist']) && !empty($infos['sequence']['sqs_missing']) )
			) {
				$class = 'dodgy';
			} else {
				$class = 'good';
			}


			$info .='<tr class="'.$class.'">'."\n";

			//-----------------------------------------------------------------------------------------------
			// Question id and name...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="questionid">'.$infos['question']['id']."</td>\n"; //question id
			$info .='<td title="questionname">'.$infos['question']['name']."</td>\n"; //question name

			//-----------------------------------------------------------------------------------------------
			// Course...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="course">';
					if(!empty($infos['course']['id'])) {
			$info .="[ ".$infos['course']['id']." ] : ";
					}
					if(!empty($infos['course']['name'])) {
			$info .=$infos['course']['name'];
					} else {
			$info .='<span class="red">orphaned</span>';
					}
			$info .="</td>\n"; //course

			//-----------------------------------------------------------------------------------------------
			// Question category...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="qcategory">';
					if(!empty($infos['q_category']['name'])) {
			$info .=$infos['q_category']['name'];
					} else {
			$info .='<span class="red">orphaned</span>';
					}
			$info .="</td>\n"; //question category

			//-----------------------------------------------------------------------------------------------
			// Quiz, if any...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="quiz">'; //quiz
					if(!empty($infos['quiz'])) {
			$info .=$infos['quiz']['id']." : ".$infos['quiz']['name'];
					} else {
			$info .="--";
					}
			$info .="</td>\n"; //quiz

			//-----------------------------------------------------------------------------------------------
			// ...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="attempted">'; //attempted?
					if($attempt_status === 'na_questions') {
			$info .="no";
					} else {
			$info .='<span class="green">yes</span>';
					}
			$info .="</td>\n"; //attempted?

			//-----------------------------------------------------------------------------------------------
			// sequence...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="sequence" class="wrap">';
					if($infos['sequence']['sequence'] !== '0') {
			$info .=$infos['sequence']['sequence'];
					} else {
			$info .='<span class="red">'
				     .$infos['sequence']['sequence']
				     .'</span>';
					}
			$info .="</td>\n"; //sequence

			//-----------------------------------------------------------------------------------------------
			// parent question for sequence questions...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="parent">';
					if($infos['sequence']['sequence'] !== '0') {
						//var_dump($infos['sequence']['parent']);
						if(!empty($infos['sequence']['parent']['correct'])) {
			$info .=$infos['sequence']['parent']['id'][0];
						} else {
			$info .='<span class="red">';
							if(!empty($infos['sequence']['parent']['id'][0])) {
			$info .=$infos['sequence']['parent']['id'][0];
							} else {
			$info .='none';
							}
			$info .='</span>';
						}
					} else {
			$info .='0';
					}
			$info .="</td>\n"; //sequence

			//-----------------------------------------------------------------------------------------------
			// Is more than one question using this sequence?...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="usage">'; //equality
					if($infos['sequence']['usage'] <= 1) {
			$info .="";
					} else {
			$info .='<span class="red">'
				     .$infos['sequence']['usage']
				     .'</span>';
					}
			$info .="</td>\n"; //usage (blank or number)

			//-----------------------------------------------------------------------------------------------
			// Are there equal placeholders to sequence questions?...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="equality">'; //equality
					if($infos['sequence']['equality'] === true) {
			$info .="yes";
					} else {
			$info .='<span class="red">no</span>';
					}
			$info .="</td>\n"; //equality (yes/no)

			//-----------------------------------------------------------------------------------------------
			// Do all sequence questions exist?...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="all Qs exist">';//all Qs exist(yes/no)
				//If the sequence is not equal to 0...
					if($infos['sequence']['sequence'] !== '0') {
				// And all sequence Qs exist...
					if(!empty($infos['sequence']['sqs_exist']) && empty($infos['sequence']['sqs_missing'])) {
			$info .="yes";
					} else {
			$info .='<span class="red">no</span>'; // Qs missing
					}} else {
			$info .='<span class="red">no</span>'; // Sequence is 0
					}

			$info .="</td>\n"; //all Qs exist (yes/no)

			//-----------------------------------------------------------------------------------------------
			// Do any other sequences use these sequence questions?...
			//-----------------------------------------------------------------------------------------------
			$info .='<td title="other sequence">';//similar sequence (sequence/null)
					if(!empty($infos['sequence']['otherseq'])) {
					foreach($infos['sequence']['otherseq'] as $other) {
						 if($other !== false) {
			$info .='<span class="red">'."yes</span>\n";
						}
					}}
			$info .="</td>\n";//similar sequence (sequence/null)
			$info .="</tr>\n";
		}}

	}

	$info .="</tbody></table>";

	$info .='<br>'."\n\n";

	echo $info;
	}
}



//-------------------------------------------------------------------------------------------
//-------------------------------------------------------------------------------------------
//-------------------------------------------------------------------------------------------
//To fix the dodgy questions!
//-------------------------------------------------------------------------------------------
//-------------------------------------------------------------------------------------------
//-------------------------------------------------------------------------------------------


//------------------------------------------------------------------------------------------------
// If a multianswer question has more sequence questions than actual placeholders in the question,
// update their sequence to 0 ONLY if the question has NOT been attempted.
//
// This could have occurred because of a bad replace, or it could be because the parent question
// was edited, removing some cloze answers. If the question has been attempted, you don't want to
// mess with it like this. It would be better to manually check the attempts for the right answer.
// NOTE: make sure you save the output so you can fix the question if this turns out to be a mistake!
//  The output will show the questiontext for the multi-answer questions.
//------------------------------------------------------------------------------------------------
function update_sequence($questionid=null,$sequence=null,$params=null) {
	global $DB;

	$message = array();

	$question = $DB->get_record_sql("SELECT * FROM {question_multianswer} WHERE question = '$questionid'");
	//var_dump($question);
	//-------------------------------------------------------------//
	//----------This is what actually gets actioned----------------//
	//-------------------------------------------------------------//
	//echo "sequence = ".$sequence."\n";

	if(!empty($question)) {
		//If the questions sequence is already 0 and the sequence to update to is 0, this question needs manual intervention...
		if( ($question->sequence == '0') && ($sequence == '0') ) {
			echo '<p class="red">'."Sequence for question [ ".$question->question." ] is already 0 and there are no other questions for this questiontext with a viable sequence.<br>\n\n";
			echo "You will need to fix this question manually.</p>\n\n";
			$problem[] = $question;
			$message['problem'] = $problem;
		} else {
			$question->sequence = $sequence;
			if($DB->update_record('question_multianswer', $question)) {
				$success[] = $question;
				$message['success'] = $success;
				echo '<p class="green">'."Sequence for question [ ".$question->question." ] was updated to [ ".$sequence." ]</p>\n\n";
				$message['newsequence'] = $sequence;
			} else {
				$problem[] = $question;
				$message['problem'] = $problem;
				echo "<p>Could not update the sequence for question [ ".$question->question." ]</p>\n\n";
			}
		}
	} else {
		echo "<p>Something went wrong. No question to check!</p>\n";
	}

	return $message;
}

//-------------------------------------------------------------------------------------------
//For each question that DOES have sequence questions in the mdl_question table
//duplicate the sequence questions and assign the new ids to the sequence column
//ONLY if ALL sequence questions exist. If some are missing, it will stuff up!
//-------------------------------------------------------------------------------------------
function has_parent_and_all_subquestions_fix($parentquestion=null,$params=null,$placeholderCount=null) {
	global $DB;
	//var_dump($duplicate);

	//Create a few arrays to output messages to...
	$message = array();

	$message['newquestion'] = array();
	$message['newQA']   	= array();
	$message['newNumQ'] 	= array();
	$message['newMCQ']  	= array();
	$message['newSAQ']  	= array();

	//Arrays for the message....
	$insertQ   = array();
	$insertQA  = array();
	$insertNum = array();
	$insertMC  = array();
	$insertSA  = array();
	$updated   = array();

	//Get the sequence for this question....
	$sequence = get_sequence_info($parentquestion,$placeholderCount);

	//var_dump($parentquestion);
	//var_dump($sequence['seq_questions']);

	$original_order = $sequence['seq_questions'];

	$seq_questions  = $sequence['seq_questions'];
	//Need to make sure you duplicate the sequence questions in the right order! This will be mandatory for fixing attempts.
	//Also have to make sure that you put the new sequence questions back into the order the question had them in, otherwise
	//other attempts will be ruined?! Argh!
	asort($seq_questions);
	//var_dump($seq_questions);

	$seq_parent 	= $sequence['parent']['id'][0];
	//var_dump($seq_parent);

	//Do not change the parent question, just all the others...
	if($parentquestion != $seq_parent) {

		echo "<h3>Fixing question: [ ".$parentquestion." ]</h3>\n\n";

		//-------------------------------------------------------------------------------------------
		// Duplicate the sequence questions and answers...
		//-------------------------------------------------------------------------------------------

		foreach($seq_questions as $seq_question) {

			//get the question category for the new sequence questions, based on the question that we're fixing
			$category = $DB->get_record_sql("SELECT category FROM {question} WHERE id = $parentquestion");
			//-------------------------------------------------------------------------------------------
			//Now, duplicate each sequence question
			//-------------------------------------------------------------------------------------------

			echo "<p>Duplicating sequence question [ $seq_question ]</p>\n";

			$SQdata = $DB->get_record_sql("SELECT * FROM {question} WHERE id = $seq_question");
			//var_dump($SQdata);
			//Create a new question based on SQdata...
			$newquestion = array(
			'category' => $category->category,
			'parent' => $parentquestion,
			'name' => $SQdata->name,
			'questiontext' => $SQdata->questiontext,
			'questiontextformat' => $SQdata->questiontextformat,
			'generalfeedback' => $SQdata->generalfeedback,
			'defaultmark' => $SQdata->defaultmark,
			'penalty' => $SQdata->penalty,
			'qtype' => $SQdata->qtype,
			'length' => $SQdata->length,
			'stamp' => make_unique_code(),
			'version' => make_unique_code(),
			'hidden' => $SQdata->hidden,
			'timecreated' => $SQdata->timecreated,
			'timemodified' => $SQdata->timemodified,
			'createdby' => $SQdata->createdby,
			'modifiedby' => $SQdata->modifiedby,
			'generalfeedbackformat' => $SQdata->generalfeedbackformat);
			//var_dump($newquestion);

			$newQ = $DB->insert_record('question', $newquestion);
			//var_dump($newQ);

			//for the message...
			$insertquestion[] = $newQ;
			//var_dump($insertQ);
			echo "\n<p>...Added new <b>question</b> to the database with id: $newQ</p>\n";
			//Add this to the success message...
			$message['newquestion'] = $insertquestion;

			//-------------------------------------------------------------------------------------------
			//Now, duplicate entries in mdl_question_answers...
			//-------------------------------------------------------------------------------------------
			$SQanswers = $DB->get_records_sql("SELECT * FROM {question_answers} WHERE question = ('$SQdata->id') ORDER BY id");
			//var_dump($SQanswer);
			//echo "---------------------------\n";

			echo "<p>";

			foreach($SQanswers as $SQanswer) {
				$newQuestionA = array(
				'question' => $newQ,
				'answer' => $SQanswer->answer,
				'fraction' => $SQanswer->fraction,
				'feedback' => $SQanswer->feedback,
				'answerformat' => $SQanswer->answerformat,
				'feedbackformat' => $SQanswer->feedbackformat);

				$newQA = $DB->insert_record('question_answers', $newQuestionA);
				//var_dump($newQA);
				$insertQA[] = $newQA;

				echo "\n...Added new <b>question_answer</b> to the database for Q [ $newQ ] with id: $newQA<br>\n";
				//Add this to the success message...
				$message['newQA'] = $insertQA;
			}
			echo "</p>\n";

			//-------------------------------------------------------------------------------------------
			//Now, duplicate entries in mdl_qtype_x or question_numerical...
			//(there are only those three as qtypes in cloze questions with {...} as questiontext...
			//-------------------------------------------------------------------------------------------
			if($SQdata->qtype = 'numerical') {
				$numericalQ = $DB->get_records_sql("SELECT * FROM {question_numerical} WHERE question = ('$SQdata->id')");
				if(!empty($numericalQ)) {
				//The above needs to be get_record(s) because numerical can have multiple answers.
					//This means that it creates multiple records in the q_num table all referring back to the same 'question'
					//Kind of like question_multianswer has the sequence questions...
				echo "<p>";
				foreach($numericalQ as $numQ) {
					//var_dump($numQ);
					//Tolerance is a not null value in the DB, but the question can be created with null through cloze questions!
					//In order to create a new one without errors, give it a tolerance of 0...
					if($numQ->tolerance == null) {
						$tolerance = '0';
					} else {
						$tolerance = $numQ->tolerance;
					}

					$newNumQ = array(
					'question' => $newQ,
					'answer' => $newQA,
					'tolerance' => $tolerance);

					$insertNumQ = $DB->insert_record('question_numerical', $newNumQ);

					$insertNum[] = $insertNumQ;

					echo "\n...Added new <b>question_numerical</b> to the database with id: $insertNumQ</br>\n";
					//Add this to the success message...
					$message['newNumQ'] = $insertNum;
				}
				}
				echo "</p>\n";
			}

			if($SQdata->qtype = 'multichoice') {
				$multichoiceQ = $DB->get_record_sql("SELECT * FROM {qtype_multichoice_options} WHERE questionid = ('$SQdata->id')");
				if(!empty($multichoiceQ)) {
				//var_dump($multichoiceQ);
				$newMCQ = array(
					'questionid' => $newQ,
					'layout' => $multichoiceQ->layout,
					'single' => $multichoiceQ->single,
					'shuffleanswers' => $multichoiceQ->shuffleanswers,
					'correctfeedback' => $multichoiceQ->correctfeedback,
					'partiallycorrectfeedback' => $multichoiceQ->partiallycorrectfeedback,
					'incorrectfeedback' => $multichoiceQ->incorrectfeedback,
					'answernumbering' => $multichoiceQ->answernumbering,
					'correctfeedbackformat' => $multichoiceQ->correctfeedbackformat,
					'partiallycorrectfeedbackformat' => $multichoiceQ->partiallycorrectfeedbackformat,
					'incorrectfeedbackformat' => $multichoiceQ->incorrectfeedbackformat,
					'shownumcorrect' => $multichoiceQ->shownumcorrect);

				$insertMCQ = $DB->insert_record('qtype_multichoice_options', $newMCQ);

				$insertMC[] = $insertMCQ;

				echo "\n<p>...Added new <b>qtype_multichoice_options</b> to the database with id: $insertMCQ</p>\n";
				//Add this to the success message...
				$message['newMCQ'] = $insertMC;
				}
			}

			if($SQdata->qtype = 'shortanswer') {
				$shortanswerQ = $DB->get_record_sql("SELECT * FROM {qtype_shortanswer_options} WHERE questionid = ('$SQdata->id')");
				if(!empty($shortanswerQ)) {
				//var_dump($shortanswerQ);
				$newSAQ = array(
				'questionid' => $newQ,
				'usecase' => $shortanswerQ->usecase);

				$insertSAQ = $DB->insert_record('qtype_shortanswer_options', $newSAQ);

				$insertSA[] = $insertSAQ;
				echo "\n<p>...Added new <b>qtype_shortanswer_options</b> to the database with id: $insertSAQ</p>\n";
				//Add this to the success message...
				$message['newSAQ'] = $insertSA;
				}
			}
		}


		//-------------------------------------------------------------------------------------------
		//Now that all the sequence questions have been duplicated,
		//assign the new array of sequence questions
		//to the sequence column of the parent question....
		//-------------------------------------------------------------------------------------------
		//First, get the new multianswer info...
		//var_dump($insertquestion);
		$QMA = $DB->get_record_sql("SELECT * FROM {question_multianswer} WHERE question = $parentquestion");

		echo "\n<p>Sequence for question [ $QMA->question ] was [ $QMA->sequence ]</p>\n";

		//This is where I need to re-order the way the sequence questions get put back in...
		//Combine the two sequences together, with old question as key for new question...
		$seq_replace = array_combine($seq_questions, $insertquestion);
		//Now for each of the $original sequence questions, replace with the new...
		foreach($original_order as $o) {
			$replaceSeq[] = strtr($o, $seq_replace);
		}

		$QMA->sequence = implode(',',$replaceSeq);
		//$QMA->sequence = implode(',',$insertquestion);

		if($DB->update_record('question_multianswer', $QMA)) {
			echo '<p class="green">'."<b>Updated sequence for [ $QMA->question ] to [ $QMA->sequence ]</b></p>\n";
			$newsequence = $QMA->sequence;
			$updated[]   = $QMA->question;
			$message['updated'] = $updated;
		} else {
			echo "Couldn't update record";
		}


		//-------------------------------------------------------------------------------------------
		//Fix any attempts made on this question...
		//-------------------------------------------------------------------------------------------

		//Get the new sequence that has just been updated for this question
		//it will be used very soon...
		$newSQs = explode(',', $newsequence);
		//var_dump($newSQs);

		$attemptids = $DB->get_records_sql("SELECT id FROM {question_attempts} WHERE questionid = '$parentquestion'");
		//var_dump($attemptids);

		$attemptinfo = $DB->get_records_sql("SELECT * FROM {question_attempts} WHERE questionid = '$parentquestion'");

		if(!empty($attemptinfo)) {
			echo "<hr>\n";
			echo "<h3>Attempt Information (just in case you need it):</h3>\n";

			foreach($attemptinfo as $attempt) {
				echo "<p>Attempt id      : [ ".$attempt->id." ]</p>\n";
				echo "<p>Question Summary: [ ".$attempt->questionsummary." ]</p>\n";
				echo "<p>Right Answer    : [ ".$attempt->rightanswer." ]</p>\n";
				echo "<p>Response Summary: [ ".$attempt->responsesummary." ]</p>\n";
				echo "<br>";
			}
		}


		if (!empty($attemptids)) {
			//Get the correct answers/values for the sequence question from mdl_question_answers
			//This will have the correct IDs needed for mdl_question_attempt_step_data...

			//var_dump($sqs);

			//Weed out any question that isn't multichoice as they are the only ones
			//that will need fixing. Absolutely must order by id! (otherwise it stuffs fixing attempts)...
			$multichoice = array();
			foreach($newSQs as $nsq) {
				$multichoice[] = $DB->get_records_sql("SELECT id FROM {question} WHERE qtype = 'multichoice' AND id = $nsq ORDER BY id");
			} // End foreach newSQs as nsq
			//var_dump($multichoice);

			$newValues = array();
			foreach($multichoice as $multi) {
				//var_dump($multi);
				if(!empty($multi)){
					foreach($multi as $MC) {
						$answers = $DB->get_records_sql("SELECT id,question FROM {question_answers} WHERE question = '$MC->id' ORDER BY question,id");
						// echo "----------------------------------------\n";
						// echo "Question Answer Group:\n";
						// echo "----------------------------------------\n";
						//var_dump($answers);
						//Outputs array -> object X however many answer values there are (a few to each question)...
						$values = array();
						foreach($answers as $key => $item) {
							//var_dump($item);
							$values[] = $item->id;
						}
						//Now put those arrays into a newValues array...
						$newValues[] = $values;
					}
				}
			}// End foreach multichoice as multi
			// echo "----------------------------------------\n";
			// echo "New values:\n";
			// echo "----------------------------------------\n";
			// var_dump($newValues);

			//Need to treat each attempt by itself, as some attempts might reference
			//different sequence questions! (If the cloze question has been edited before)...

			foreach($attemptids as $attempt) {
				//echo "----------------------------------------\n";
				//echo "Attempt id: $attempt->id\n";
				//echo "----------------------------------------\n";
				//var_dump($attempt);
				//Now get the attempt step ids for each of the question attempts where the state is todo.
				//(The three states are: todo, complete, graded-right/partial/invalid). We need todo.
				//We only need the id's, really...
				$steps = $DB->get_records_sql("SELECT id FROM {question_attempt_steps} WHERE state = 'todo' AND questionattemptID = $attempt->id");
				//var_dump($steps);

				//Sometimes there is no 'todo' so you have to take the first 'complete' instead!
				if (empty($steps)) {
					$steps = $DB->get_records_sql("SELECT id FROM {question_attempt_steps} WHERE state = 'complete' AND questionattemptID = $attempt->id ORDER BY id LIMIT 1");
				}


				//Need to treat each step separately...
				//echo "----------------------------------------\n";
				//echo "New Loop: ";
				//echo "----------------------------------------\n";
				foreach($steps as $step) {
					//var_dump($step);
					$stepx = $step->id;

					//Now we need the step data so we can change the values in the value column.
					//But we only need the ones that are _sub?_order...
					//Had it ordered by name, but if there were more than 10 of them, the order got screwed up (_sub10_order, sub1_order, etc)
					//By id seemed to work. But in some cases, the order was screwed up.
					//Finally found this...
					$stepdata = $DB->get_records_sql("SELECT * FROM {question_attempt_step_data} WHERE name LIKE '%_order' AND attemptstepid = '$stepx' ORDER BY NULLIF(regexp_replace(name, '\D', '', 'g'), '')::int");
					//var_dump($stepdata);
					//Outputs array -> object X however many _sub_order values there are...

					$oldValues = array();
					$oldValuesArray = array();
					foreach($stepdata as $key => $item) {
						//Get the oldValues as an array of string values...
						$oldValues[] = $item->value;
						//explode the strings into an array for the replacement list...
						$oldValuesArray[] = explode(',', $item->value);
					}
					// echo "----------------------------------------\n";
					// echo "Old values:\n";
					// echo "----------------------------------------\n";
					// var_dump($oldValues);

					//Sort the oldValues from lowest->highest in their arrays...
					//(Some values are shuffled, which will ruin the replacement)
					$oldList = array();
					foreach($oldValuesArray as $ov) {
						sort($ov);
						$oldList[] = $ov;
					}
					// echo "----------------------------------------\n";
					// echo "Old List:\n";
					// echo "----------------------------------------\n";
					// var_dump($oldList);

					//Now we need to create an array where the oldValue is the key
					//And the newValue is the value (for the replacement function)...
					$newV = new ArrayIterator(array_values($newValues));
					$oldV = new ArrayIterator(array_values($oldList));

					$result = new MultipleIterator;
					$result->attachIterator($oldV);
					$result->attachIterator($newV);

					// echo "Result: ";
					// var_dump($result);

					$replaceMArray = array();
					//Combine the two arrays (oldValue is the key, newValue is the value...
					foreach($result as $r) {
						// echo "----------------------------------------\n";
						// echo "results as r :\n";
						// echo "----------------------------------------\n";
						// var_dump($r);
						//Make sure both arrays have the same number of elements
						//If not, it means some question-parts were deleted after attempts were made...
						if(sizeof($r[0]) == sizeof($r[1])) {
							$replaceMArray[] = array_combine($r[0],$r[1]);
						} else {
							//This shouldn't happen now, but if it does...

							echo '<p class="red">Error: Your replacement arrays are not equal for attempt:'.$attempt->id."<br>\n";
							echo "Make sure no sequence questions are missing for Q [ $parentquestion ] and try again.</p>\n";

						};
					}
					//var_dump($replaceMArray);

					//Now make sure the $replaceMArray is not empty before doing the replacement....
					if(!empty($replaceMArray)) {
						//Iterate over the replaceArray so we can flatten the multi array into one...
						$values = new RecursiveIteratorIterator(new RecursiveArrayIterator($replaceMArray));

						//Reassign the key->value relationship...
						$replace = array();
						foreach($values as $key => $item) {
							$replace[$key] = $item;
						}
						//Now we have our replacement values!
						// echo "----------------------------------------\n";
						// echo "Replacement values:\n";
						// echo "----------------------------------------\n";
						// var_dump($replace);

						$result = array();
						foreach($oldValues as $old) {
							//var_dump($old);
							$result[] = strtr($old, $replace);
						}

						// var_dump($oldValues);
						// var_dump($result);

						$subscount = sizeof($result);
						$y = 0;
						while($y < $subscount) {
							foreach($stepdata as $step => $item) {
								// echo "original step: ";
								// var_dump($step);
								// echo "original item: ";
								// var_dump($item);
								//echo "<p>Original step_data value for [ $item->id ] was [ $item->value ]<br>\n";
								$item->value = $result[$y];
								$y++;
								// echo "y = ";
								// var_dump($y);
								// echo "New item: ";
								// var_dump($item);

								if($DB->update_record('question_attempt_step_data', $item)) {
									//echo "Updated step_data value for: [ $item->id ] now [ $item->value ]</p>\n";
								} else {
									echo '<p class="red">'."Couldn't update record for [ $item->id ]</p>";
								}
							}
						}
					}
				}

			}// End foreach attemptids as attempt

		}//End if ! attemptids

		echo "<hr>\n";
	}//End if parent != seq_parent

	return $message;

}



//-------------------------------------------------------------------------------------------
//The function that calls the sequence update function...
//-------------------------------------------------------------------------------------------
function run_fix_update($getinfo,$options=null) {
	global $DB;

	//var_dump($getinfo);

	$data = array();

	//First, we need to get the info for the questions that actually need fixing....

	foreach($getinfo['allsequences'] as $sequence) {

		foreach($getinfo[$sequence] as $attempt_status => $sequenceinfo) {

		foreach($sequenceinfo as $info) {
			//var_dump($info);

			//If the equality is false or there are sequence questions missing or the sequence is 0 or the parent is not correct...
			if( ($info['sequence']['equality'] === false) || (!empty($info['sequence']['sqs_missing'])) || ($info['sequence']['sequence'] === '0') ) {
			//So long as the questions haven't been attempted....
				if($attempt_status !== 'attempted_questions') {
					$data['update']['not_attempted'][] = $info['question']['id'];
				} //end if statement
				//If they have, chuck them into an array that I can look at later...
				else {
					$data['update']['attempted'][] = $info['question']['id'];
				}
			}

			//If the equality is equal and there are no sequence questions missing
			//grab the sequence (just the first one will do)....
			if( ($info['sequence']['equality'] === true) && (empty($info['sequence']['sqs_missing']))) {
				//var_dump( $info['sequence'] );
				$data['update']['viablesequence'] = $info['sequence']['sequence'];
			}

		} //endforeach($sequenceinfo as $infos)

		} //end foreach($getinfo[$sequence] as $attempt_status => $sequenceinfo)
	} //end foreach($getinfo['allsequences'] as $sequence)



	$message = array();
	$message['fix1-problem'] = array();
	$message['fix1-success'] = array();


	//------------------------------ APPLY NEW SEQUENCE -----------------------------------

	//var_dump($data);

	if(!empty($data['update']['not_attempted'])) {

	echo '<div class="clearer"></div>';
	echo '<div class="box">';
	echo "<hr>\n";
	echo "<p>Updating sequence on dodgy questions to a viable sequence or 0...</p>\n";
	echo "<hr>\n";

	foreach($data['update']['not_attempted'] as $question) {

		//IF there's a viable sequence, use that. If not, update to '0'...

		if(!empty($data['update']['viablesequence'])) {
			$newsequence = $data['update']['viablesequence'];
		} else {
			$newsequence = '0';
		}

		$fix1[] = update_sequence($question,$newsequence,null);

	}
	echo "</div>\n";
	echo '<div class="clearer"></div>';
	}

	//Might want to do something different with the attempted questions?
	//For now, just do the same as the non_attempted?
	if(!empty($data['update']['attempted'])) {

	echo '<div class="clearer"></div>';
	echo '<div class="box">';
	echo "<hr>\n";
	echo "<p>Updating sequence on dodgy questions to a viable sequence or 0...</p>\n";
	echo "<hr>\n";


	foreach($data['update']['attempted'] as $question) {

		//IF there's a viable sequence, use that. If not, update to '0'...
		if(!empty($data['update']['viablesequence'])) {
			$newsequence = $data['update']['viablesequence'];
		} else {
			$newsequence = '0';
		}

		$fix1[] = update_sequence($question,$newsequence,null);

	}

	echo "</div>\n";
	echo '<div class="clearer"></div>';
	}

	if(!empty($fix1)) {
		//echo "<hr>\n";
		//echo "<p>Result for update sequence...</p>\n";
		//var_dump($fix1);
		//Output messages for this and update $getinfo array with new values...
		foreach($fix1 as $fix) {
			if(!empty($fix['problem'])) {
				foreach($fix['problem'] as $problem) {
					$noupdate1[] = $problem;
					$message['fix1-problem'][] = $noupdate1;
				}
			}
			if(!empty($fix['success'])) {
				foreach($fix['success'] as $success) {
					$updated1[] = $success;
					$message['fix1-success'][] = $updated1;
				}
			}
		}
		//echo "<hr>\n";
	}

	return $message;
}

//-------------------------------------------------------------------------------------------
//The function that calls the fix all questions with a viable sequence function...
//-------------------------------------------------------------------------------------------
function run_fix_duplicate($getinfo,$options=null) {
	global $DB;

	//var_dump($getinfo);

	$data = array();

	//First, we need to get the info for the questions that actually need fixing....

	foreach($getinfo['allsequences'] as $sequence) {

		foreach($getinfo[$sequence] as $attempt_status => $sequenceinfo) {

		foreach($sequenceinfo as $info) {

			//If the equality is equal and there are no sequence questions missing and there are multiples of this sequence...
			if( ($info['sequence']['equality'] === true) && (empty($info['sequence']['sqs_missing'])) && ($info['sequence']['usage'] > 1) ) {
				//var_dump( $info['sequence'] );
				$data['fix']['question'][] = $info['question']['id'];
			}
			//If the parent is wrong, but everything else is fine, fix this question by running the duplicate fix (it will assign the correct parent!)...
			if( !empty($info['sequence']['parent']['wrong']) ) {
				//If the equality is equal and there are no sequence questions missing and there are multiples of this sequence...
				if( ($info['sequence']['equality'] === true) && (empty($info['sequence']['sqs_missing'])) ) {
					//var_dump( $info['sequence'] );
					$data['fix']['question'][] = $info['question']['id'];
				}
			}

		} //endforeach($sequenceinfo as $infos)

		} //end foreach($getinfo[$sequence] as $attempt_status => $sequenceinfo)
	} //end foreach($getinfo['allsequences'] as $sequence)
	//----------------------------------------------- DUPLICATE -------------------------------------------------
	//Duplicate sequence questions that have equal plaecholders to sequence questions and all sub-questions exist...

	$message = array();

	if(!empty($data['fix']['question'])) {

	$message['fix3-newqs']   = array();
	$message['fix3-updated'] = array();
	$message['fix3-newQA']   = array();
	$message['fix3-newNumQ'] = array();
	$message['fix3-newMCQ']  = array();
	$message['fix3-newSAQ']  = array();


	$questiontofix 	  = $data['fix']['question'];
	$placeholderCount = $getinfo['qc_count'];

	echo '<div class="clearer"></div>';
	echo '<div class="box">';
	echo "\n<hr>";
	echo "<p>Attempting to fix questions that have a parent and all subquestions.<br>
		  If successful, will duplicate subquestions and assign new sequence and fix attempts.</p>\n";
	echo "<hr>\n";


	foreach($questiontofix as $question) {
		$fix3[] = has_parent_and_all_subquestions_fix($question,null,$placeholderCount);
	}


	echo "</div>";
	echo '<div class="clearer"></div>';

	//Output messages for this...
	if(!empty($fix3)) {
		//echo "<hr>\n";
		//echo "<p>Result for duplicate questions and apply new IDs...</p>\n";
		//var_dump($fix3);

		foreach($fix3 as $fix) {
			if(!empty($fix['newquestion'])) {
				foreach($fix['newquestion'] as $no) {
					$newquestion[] = $no;
					$message['fix3-newqs'] = $newquestion;
				}
			}

			if(!empty($fix['updated'])) {
					//updated is not an array, so just output the string...
				foreach($fix['updated'] as $update) {
					$updated3[] = $update;
					$message['fix3-updated'] = $updated3;
				}
			}
			if(!empty($fix['newQA'])) {
				foreach($fix['newQA'] as $newQA) {
					$newQA1[] = $newQA;
					$message['fix3-newQA'] = $newQA1;
				}
			}
			if(!empty($fix['newNumQ'])) {
				foreach($fix['newNumQ'] as $newNumQ) {
					$newNumQ1[] = $newNumQ;
					$message['fix3-newNumQ'] = $newNumQ1;
				}
			}
			if(!empty($fix['newMCQ'])) {
				foreach($fix['newMCQ'] as $newMCQ) {
					$newMCQ1[] = $newMCQ;
					$message['fix3-newMCQ'] = $newMCQ1;
				}
			}
			if(!empty($fix['newSAQ'])) {
				foreach($fix['newSAQ'] as $newSAQ) {
					$newSAQ1[] = $newSAQ;
					$message['fix3-newSAQ'] = $newSAQ1;
				}
			}
		}
	} else {
		echo "No questions to duplicate. Moving on...\n\n";
	}

	}

	//echo sizeof($message);
	if(sizeof($message) >= 1) {
		return $message;
	}
}


//---------------------------------------------------------
// Update dodgy sequences with missing question ids to 0
// Run for first fix...
//---------------------------------------------------------
if (!empty($options['fix'])) {
	//Find sequences with a lot of commas, but no numbers...
	$junksequences = $DB->get_records_sql("
	SELECT *
	FROM {question_multianswer}
	WHERE sequence LIKE ''
	   OR sequence LIKE ','
	   OR sequence LIKE '%,,%'");
	//var_dump($junksequences);

	//If you have a result, update the record in the database
	//changing the commas to 0, as an empty value returns an error...
	if (!empty($junksequences)) {
		foreach ($junksequences as $dodgy) {
			//var_dump($dodgy);
			$dodgy->sequence = '0';
			if($DB->update_record('question_multianswer', $dodgy)) {
				echo "Updated question.id $dodgy->id\n";
			} else {
				echo "couldn't update record";
			}
		}
	} else {
		echo "<p>No questions have only commas for a sequence. This is a good thing...</p>\n\n";
	}
}

if($options['questions']) {
	//////////////////////////////////////////////////////
	//If you have specified a question (not a course)....
	//////////////////////////////////////////////////////
	$questions = $DB->get_recordset_sql('
		SELECT q.id as question,q.questiontext,qm.sequence
		FROM mdl_question q
		JOIN mdl_question_multianswer qm ON qm.question = q.id
	'. $where, $params);

	$questionGroups = array();
	//var_dump($questions);
	foreach($questions as $question) {
		//var_dump($question);

		$questiontext 	= $question->questiontext;
		$plaintext		= plainText($questiontext);
		$noimgtext		= noimgText($plaintext);

		$sequence 		= $question->sequence;

		if(!empty($plaintext)) {
			$plaintextGroups[$plaintext][$sequence][] = $question;
		} else {
			echo "\nNo question text. Something must have gone wrong. Did you use * for all questions, or the questiontext for a single question?\n";
		}

		if (!empty($noimgtext)) {
			$noimgGroups[$noimgtext][$sequence][] = $question;
		} else {
			echo "\nNo question text. Something must have gone wrong. Did you use * for all questions, or the questiontext for a single question?\n";
		}
	}

	$questions->close();

	//var_dump($plaintextGroups);

	echo '<!doctype html><html><head><meta charset="utf-8"><title>Multianswer Questions</title>'."\n\n";
	echo '<style>'."\n\n";

	echo 'table {
			border: 1px solid #CBCBCB;
			width: 100%;
			max-width: 100%;
			font-family: arial;
			font-size: 90%;
			border-collapse: collapse;
			}
			th {
				background-color: #e8e8e8;
				vertical-align: top;
			}
			th, td {
				border: 1px solid #CBCBCB;
				padding: 8px 16px;
			}
			tr {vertical-align: top;}
		  ';

	echo '.green {
			background-color: #7CFC00;
			padding: 2px 5px;
			font-weight: bold;
		  }
		  ';

	echo '.red {
			background-color: #e697b0;
			padding: 2px 5px;
			font-weight: bold;
		  }
		  ';

	echo 'p {word-break: break-all;}';

	echo '.wrap {word-break: break-all;} ';

	echo '.dodgy {display: table-row;}';

	echo '.good {display: table-row;}';

	echo '.hide {display: none;}';

	echo '.box {border: 1px solid #ccc; padding: 60px; width:50%; margin: 0 auto;}';

	echo '.clearer {clear:both; display:block; width:100%;}';

	echo 'button {padding: 5px; margin-bottom: 10px;}';

	echo '</style>';
	echo '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>';

	echo '<script type="text/javascript">
			$(document).ready(function () {
			$("button[class^=table]").on("click", function () {
				var id = this.className;
				var rows = $("table." + id + " tr.good");

				if( $(rows).hasClass("good") ) {
					if( $(rows).hasClass("hide") ) {
						$(rows).removeClass("hide")
					} else {
						$(rows).addClass("hide")
					}
				}
			});
		});
		';

	echo '$(document).ready(function () {
			$("[id=hideall]").on("click", function () {
				var rows = $("tr.good");

				if( $(rows).hasClass("hide") ) {
					$(rows).removeClass("hide")
				} else {
					$(rows).addClass("hide")
				}
			});
		});
		';

	echo '</script>';

	echo '</head>';
	echo "\n\n";

	echo '<body>';
	echo '<button id="hideall">Hide/Show ALL Good Rows</button>';
	echo "\n\n";

	//Add a button that can filter all results to show just the broken questions....
	//

	$x = '1';
	$result = array();

	foreach($plaintextGroups as $text => $group) {
		//var_dump($group);

		$getinfo = get_info($text,$group,null);
		//var_dump($getinfo);
		if(!empty($getinfo)) {
			if (!empty($options['info'])) {
				//var_dump($getinfo);
				return_info($getinfo,$x,$options);
			}
			else if (!empty($options['fix'])) {
				//Run an info gathering mission...
				return_info($getinfo,$x,$options);
				//Now run the first part of the fix...
				//fix the sequence...
				$fix1 = run_fix_update($getinfo,$options);
				//Put the result into an array for later reporting...
				$result[] = $fix1;

				//If the first fix returned a result,
				//you need updated info for the next fix...
				if(!empty($fix1)) {
					//get updated information...
					$getinfo = get_info($text,$group,null);
				}
				//run the next part of the fix...
				//duplicate the duplicate sequence questions...
				$fix2 = run_fix_duplicate($getinfo,$options);
				//Put the result into an array for later reporting...
				$result[] = $fix2;
				//echo "fix2:\n";
				//var_dump($fix2);
				//echo "result:\n";
				//var_dump($result);
				//If the second fix returned a result, you need updated info again...
				if(!empty($fix2)) {
					$x++;
					//get updated information...
					$getinfo = get_info($text,$group,null);
					//Now return the new info...
					echo '<h1 style="text-align:center;">Updated info:</h1>'."\n";
					//Make sure you specify 'verbose' or it won't show anything!
					$options['verbose'] = true;
					return_info($getinfo,$x,$options);
				}

			}
		}

		$x++;
	}

	echo "</body></html>";

}

echo "<hr>\n";
echo "<p>Done!</p>\n";
echo "<hr>\n";

if(!empty($options['fix'])) {

	foreach($result as $message) {
		//var_dump($message);
		if(!empty($message['fix1-problem'])) {
			$update_problem[] 	= count($message['fix1-problem']);
		}
		if(!empty($message['fix1-success'])) {
			$update_success[] 	= count($message['fix1-success']);
		}
		if(!empty($message['fix3-updated'])) {
			$new_updated[]		= count($message['fix3-updated']);
		}
		if(!empty($message['fix3-newqs'])) {
			$new_qs[]			= count($message['fix3-newqs']);
			$new_QA[]			= count($message['fix3-newQA']);
			$new_NumQ[]			= count($message['fix3-newNumQ']);
			$new_MCQ[]			= count($message['fix3-newMCQ']);
			$new_SAQ[]			= count($message['fix3-newSAQ']);
		}
	}

	echo "\n";

	echo '<p class="red">'."Fails:</p>\n";
	if(!empty($update_problem)) {
		echo "<p>...[".array_sum($update_problem)."] question(s) could not be fixed and requires manual intervention.</p>\n";
	} else {
		echo "<p>NIL</p>\n";
	}

	echo "\n";
	echo '<p class="green">'."Successes:</p>\n";
	if(!empty($update_success)) {
		echo "<p>...[ <b>".array_sum($update_success)."</b> ] sequence(s) updated.</p>\n";
	}
	if(!empty($new_qs)) {
		echo "<p>...[ <b>".array_sum($new_qs)." </b>] new <b>questions</b> were added to the mdl_question table.<br>\n";
		echo "...[ <b>".array_sum($new_QA)." </b>] new <b>answers</b> were added to the mdl_question_answers table.<br>\n";
		echo "...[ <b>".array_sum($new_NumQ)." </b>] new <b>numerical</b> questions were added to the mdl_question_numerical table.<br>\n";
		echo "...[ <b>".array_sum($new_MCQ)." </b>] new <b>multi-choice</b> questions were added to the mdl_qtype_multichoice_options table.<br>\n";
		echo "...[ <b>".array_sum($new_SAQ)." </b>] new <b>short-answer</b> questions were added to the mdl_qtype_shortanswer_options table.</p>\n";
	}

} else if (!empty($options['info'])) {
	//foreach ($showmeinfo as $info) {
		//could put values here...
	//};
} else {
	echo "...To fix all corrupt questions, run: \$sudo -u wwwrun php ./fix_multianswer_questions_v2.php --questions=* --fix --verbose". "\n\n"
		."...To fix a specified question,  run: \$sudo -u wwwrun php ./fix_multianswer_questions_v2.php --questions=[type in questionid] --fix --verbose". "\n\n";
}

?>
