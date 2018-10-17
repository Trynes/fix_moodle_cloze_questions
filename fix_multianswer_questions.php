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
require_once($CFG->dirroot . '/question/engine/lib.php');

// Get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'questions'         => false,
		'course'			=> false,
		'info'				=> false,
		'savetofile'		=> false,
        'fix'               => false,
        'help'              => false
    ),
    array(
        'h' => 'help',
        'q' => 'questions',
		'c' => 'course',
		's' => 'savetofile',
        'f' => 'fix',
		'i' => 'info'
    )
);

//If you've asked for help, or you haven't included * or a question id, show the help...
if ($options['help']) {
    $help =
"
-------------------------------------------------------------------------------------------------------------------------
DISCLAIMER: This is not a Moodle-sanctioned script! Moodle.org have nothing to do with this.

This script works for Moodle 3.3.6, with a Postgres 9 database. You can test it on other systems, 
but I give no guarantees that it will work as expected.

I highly suggest testing it first on a copy of your system to ensure it doesn't corrupt your whole questions database, 
not that I think it should! I've run it against a few courses in our system and nothing untoward has happened so far!

I suggest, if you DO use it on your production instance, that you also test it against a few courses first. 
NOTE: if you fix ONE course, it will likely affect other courses, since that is the nature of the beast!
------------------------------------------------------------------------------------------------------------------------

------------------------------------------------------------------------------------------------------------------------
IMPORTANT: This script will run for a long time on big systems if called for all questions. 
Run ONLY while in maintenance mode as another user may simultaneously edit one of the questions 
being checked/fixed and cause more problems.
------------------------------------------------------------------------------------------------------------------------

This is a multi-step script.

THE PROBLEM:

The SQL below shows all the questions with a sequence of '2783831,2783832,2783833'

SELECT question,sequence FROM mdl_question_multianswer WHERE sequence = '2783831,2783832,2783833';

question | ------- sequence ---------- |
----------+-----------------------------+
5334552 | 2783831,2783832,2783833
3756523 | 2783831,2783832,2783833
3758257 | 2783831,2783832,2783833
6473599 | 2783831,2783832,2783833
7302100 | 2783831,2783832,2783833
9878958 | 2783831,2783832,2783833

Ideally, there should only be ONE result for this sequence:

question | ------- sequence ---------- |
----------+-----------------------------+
3758257 | 2783831,2783832,2783833 |

If you looked at the mdl_question result for the first sequence question '2783831', you would see that the parent is one of the questions above:

--- id -- | parent |
----------+----------+
2783831 | 3758257

If all the questions in the first column were correct, however, they would look more like this
(taking just the first question in the sequence as an example):

question | ------- qma.sequence ------- | --- q.id --- | q.parent
----------+-----------------------------+----------+---------
5334552 | 5334553,5334554,5334555 | 5334553 | 5334552
3756523 | 3756524,3756525,3756526 | 3756524 | 3756523
3758257 | 2783831,2783832,2783833 | 2783831 | 3758257
6473599 | 6473600,6473601,6473602 | 6473600 | 6473599
7302100 | 7302101,7302102,7302103 | 7302101 | 7302100
9878958 | 9878959,9878960,9878961 | 9878959 | 9878958



Why is this a problem? 

Because if someone deletes the parent question '3758257', every other question with 
the sequence '2783831,2783832,2783833' will now have NO sequence questions listed 
and this will cause an error within Moodle! If you check the mdl_question_multianswer 
table for the question, you will find it now likely has a sequence of ',,' instead.



To fix this, the script will do the following...

1. Find all questions in the mdl_question_multianswer table in the DB for incorrect/invalid sequences; 
i.e. sequence is null, or ',,,,' (no subquestions listed, or only commas).
If any questions have an EMPTY/INVALID sequence, it will update the sequence to '0'. This is to ensure 
courses don't fail on import when it can't find the seqeunce questions.

2. Find and strip ALL HTML (except for img filenames) from multianswer questiontext for later use 
(used to check if the same/similar questiontext exists in other questions).

3a. If ALL sequence questions exist, it will duplicate those sequence questions and then assign those 
new ids to the main questions that are NOT the parent of the original sequence questions. 
This will make all those results in the first SQL example above look like the results from the second example SQL.

3b. If NONE of the sequence questions exist, it will look for the same, & then stripped, questiontext
within the database to see if there are any questions that match and DO have sequence questions. If it 
finds a matching question, it will assign question A the sequence of question B (the one it just matched to). 
Once that is done, it will complete step 3a.

4. Once the questions all have new sequence questions (and answers in the mdl_question_answers table), 
it will then go through and fix any attempts that might have been made on these questions, 
assigning the new sequence question answers to the attempts so that you don't get 'the answer 
was changed after attempt' (or whatever the message is).

5. TO BE WRITTEN: If there are no matches with other questions, meaning you now have a main 
question with no sequence questions, and just get {#1}, {#2}, in your main question instead 
of {1:SA:=bla}..... I'm not sure yet!

------------------------------------------------------------------------------------------------------------------------

IMPORTANT REMINDER: This script will run for a long time on big systems if called for all questions. Run ONLY while in maintenance mode, or for a particular course that you know will not be edited while running the script, as another user may simultaneously edit one of the questions being checked/fixed and cause more problems.

------------------------------------------------------------------------------------------------------------------------


HOW TO USE:

    Copy the file to moodle/admin/cli;

From the server console:

You can get information on QUESTIONS that might have more than one sequence match by running:

ALL questions
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=* --info

a few questions:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --info

a single question:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --info

Now, to run the fix:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=* --fix
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --fix
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --fix


OR, if you would like to check a COURSE in particular:

You can get information on COURSES that might have more than one sequence match by running:

ALL courses
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=* --info

a few ourses:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456,223344,445566 --info

a single ourses:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456 --info

Now, to run the fix:
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=* --fix
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456,223344,445566 --fix
    sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456 --fix

";

    echo $help;
    die;
}

//$DB->set_debug(true);

//New questions need a unique code for their stamps...
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
	//This is if the img has /> at the end...
	$plaintext = preg_replace('%<img\s.*?src=".*?/?([^/]+?(\.gif|\.png|\.jpg))"\s.*?\/>%s', '[img: '.$image.'] ', $plaintext);
	//This is if it doesn't!...
	$plaintext = preg_replace('%<img\s.*?src=".*?/?([^/]+?(\.gif|\.png|\.jpg))"\s.*?>%s', '[img: '.$image.'] ', $plaintext);
	//If there are any non-breaking space, remove them...
	$plaintext = preg_replace('/&nbsp;/', ' ', $plaintext);
	//If there are blank spaces more than one space, make it one...
	$plaintext = preg_replace('/\s\s+/', ' ', $plaintext);
	
	
	return $plaintext;
}

$checklist = preg_split('/\s*,\s*/', $options['questions'], -1, PREG_SPLIT_NO_EMPTY);
$courselist = preg_split('/\s*,\s*/', $options['course'], -1, PREG_SPLIT_NO_EMPTY);

//if * is in the questionlist (meaning all questions)
if (in_array('*', $checklist)) {
    $where = 'WHERE sequence IN (SELECT sequence FROM mdl_question_multianswer GROUP BY sequence HAVING COUNT(*) > 1)'; //Just want the sequences that appear more than once...
    $params = array(); //No params
} else {//The user has specified a question to check
	if($options['questions']) {
		//This will only check to see if the specified question is in qma.question column
		//Not the qma.sequence column, so it will only show if it's a base cloze question...
		list($sql, $params) = $DB->get_in_or_equal($checklist, SQL_PARAMS_NAMED, 'question');
		//Where question is in or equal to the 'question' specified in the questionlist.
		$where = 'WHERE question '. $sql;
	} else if($options['course']) {
		//This will only check to see if the specified course is in c.id column...
		list($sql, $params) = $DB->get_in_or_equal($courselist, SQL_PARAMS_NAMED, 'id');
		//Where the id is in or equal to the 'courseid' specified in the courselist.
		$where = 'WHERE c.id '. $sql;
	}
} 

//The amount of questions to check...
//get the count of 'question's that returned a result in mdl_question_multianswer
//where the 'where' matches the questionlist option (* or a question id)
if($options['questions']) {
	$sequencescount = $DB->get_field_sql('SELECT count(sequence) FROM {question_multianswer} '. $where, $params);

	if (!$sequencescount) {
		cli_error("No questions/sequences found.");
	} else {
		echo "\n---------------------------------------------\n"
			." ||| Found $sequencescount problem sequences(s)... |||\n"
			."---------------------------------------------\n";
}}
else if($options['course']) {
	$sqcoursecount = $DB->get_field_sql('
		SELECT count(sequence) 
		FROM {question_multianswer} qm
		JOIN {question} q ON qm.question = q.id
		JOIN {question_categories} qc ON q.category = qc.id
		JOIN {context} ctxt ON qc.contextid = ctxt.id
		JOIN {course} c ON ctxt.instanceid = c.id '. $where, $params);

	if (!$sqcoursecount) {
		cli_error("No questions/sequences found for this course.");
	} else {
		echo "\n---------------------------------------------------------------\n"
			." ||| Found $sqcoursecount multianswer question(s) in this course... |||\n"
			."---------------------------------------------------------------\n";
}}
	
//require_once($CFG->dirroot. '/lib/questionlib.php');

function get_sequence_info($sequence=null,$params=null) {
	global $DB;
	//var_dump($sequence);
	//Go through each of the dodgy multianswer questions
	//and return the following data:
	//id, parent, name, question text, sequence
	
	$data = array();
	$data['sequence'] = $sequence;
	
	$questionids = array();
	$records = $DB->get_records_sql("SELECT question FROM {question_multianswer} WHERE sequence = '$sequence'");
	//var_dump($records);	
	//Get the matching question ids for this sequence...
	foreach ($records as $record) {
		$questionids[] = $record->question;
	}
	
	$questioncount = count($questionids);
	//var_dump($questionids);
		
	$data['duplicateids'] 	= $questionids;	
	$data['duplicatecount'] = $questioncount;
	

	$attempted = array();
	$category = array();
	$courseid = array();
	$coursename = array();
	$quiz = array();
	$qtext = array();
	foreach($questionids as $question) {
		//var_dump($question);
		//Have any of these questions been attempted?
		//Need to find the question.id in the mdl_question_attempts table
		//under questionid...
		$attempts = $DB->get_records_sql("SELECT id FROM {question_attempts} WHERE questionid = '$question'");
		//var_dump($attempts);
		if (!empty($attempts)) {			
			$attempted[] = $question;
			$data['attempted'] = $attempted;
			//var_dump($data['attempted']);
		} 
		//var_dump($data['attempted']);
		
		//Find category information for this question...
		//Need to do it like this, because it might be at the quiz context level
		//not course context level
		$categories = $DB->get_records_sql("
		SELECT q.category as categoryid,qc.name as category
		FROM {question} q 
		JOIN {question_categories} qc ON q.category = qc.id
		WHERE q.id = $question");
		//var_dump($category);
		
		foreach($categories as $cat) {
			$category[] = $cat->category;
			$data['category'] = $category;
		}
		//var_dump($data['category']);
		
		//If category is at the quiz level (70), need to get the path for the quiz
		//in order to get the course context...
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
			}
		}
		
		//Now find out what course(s) the question is in...
		$course = array();
		//If the question is in a category at the quiz context level...
		if(!empty($coursecontext)) {
			foreach($coursecontext as $ccxt) {
				$course[] = $DB->get_records_sql("
				SELECT cxt.id,c.id as courseid,c.shortname
				FROM {context} cxt
				JOIN {course} c ON c.id = cxt.instanceid
				WHERE cxt.id = $ccxt");
			}
		} else {
			//otherwise, it's at the course context level...
			$course[] = $DB->get_records_sql("
			SELECT q.category as categoryid,qc.name as category,c.id as courseid,c.shortname
			FROM {question} q 
			JOIN {question_categories} qc ON q.category = qc.id 
			JOIN {context} cxt ON qc.contextid = cxt.id 
			JOIN {course} c ON c.id = cxt.instanceid 
			WHERE q.id = $question");			
		}
		//var_dump($course);
		
		if ($course) {
			foreach($course as $c => $item) {	
				foreach($item as $cinfo) {		
					$courseid[] = $cinfo->courseid;
					$data['courseid'] = $courseid;
					
					$coursename[] = $cinfo->shortname;
					$data['course'] = $coursename;					
				}
			}
		}
		$data['coursecount'] = count($courseid);
		
		//Do any of these question appear in a quiz?
		
		$used = $DB->get_records_sql("SELECT qs.quizid,qz.name FROM {quiz_slots} qs JOIN {quiz} qz ON qs.quizid = qz.id WHERE questionid = '$question'");
		//var_dump($used);
		if (!empty($used)) {
			foreach($used as $info) {
				$quiz[] = $info->name;
				$data['quiz'] = $quiz;
			} 
		}
		
		$text = $DB->get_records_sql("SELECT questiontext FROM {question} WHERE id = '$question'");
		foreach($text as $qt) {
			$qtext[] = $qt->questiontext;
			$data['qtext'] = $qtext;
		}
	}
		
	$atCid = array();
	$atCn = array();
	$atQn = array();
	if (!empty($data['attempted'])) {
		arsort($data['attempted']);
		foreach($data['attempted'] as $attempt) {
			//var_dump($attempt);
			//Get attempted course info...
			$attemptedCourse = $DB->get_records_sql("
			SELECT q.category as categoryid,qc.name as category,c.id as courseid,c.shortname
			FROM {question} q 
			JOIN {question_categories} qc ON q.category = qc.id 
			JOIN {context} cxt ON qc.contextid = cxt.id 
			JOIN {course} c ON c.id = cxt.instanceid 
			WHERE q.id = $attempt");
			//var_dump($attemptedCourse);
			foreach($attemptedCourse as $atc) {
				//var_dump($atc);
				$atCid[] = $atc->courseid;
				$atCn[] = $atc->shortname;
			}
			
			//Get attempted quiz info...
			$attemptedQuiz = $DB->get_records_sql("
			SELECT qs.quizid,qz.name 
			FROM {quiz_slots} qs 
			JOIN {quiz} qz ON qs.quizid = qz.id 
			WHERE questionid = '$attempt'");
			//var_dump($attemptedQuiz);
			foreach($attemptedQuiz as $atq) {
				$atQn[] = $atq->name;
			}
		}
		$data['attemptedCourseID'] = $atCid;
		$data['attemptedCourseName'] = $atCn;
		$data['attemptedQuiz'] = $atQn;
	}
	//arsort();

	
				
	//Check that each question in a sequence has a record in the question table...
	//If it does, find the parent of each question and return it
	
	//First, you have to explode the sequence string into an array...
	$sqs = explode(',', $sequence);
	//var_dump($sqs);
	//Now check over each question id, placing values in the appropriate array...
	$sqs_exist = array();
	$sqs_missing = array();
	
	foreach ($sqs as $check) {
		if ($DB->get_record('question', array('id'=>$check))) {
			$sqs_exist[] = $check;			
			//$data['sqs_missing'] = 'all present';
		} else {
			//$data['sqs_exist'] = 'all missing';
			$sqs_missing[] = $check;				
		}
	}
	$data['sqs_exist'] = $sqs_exist;
	$data['sqs_missing'] = $sqs_missing;
	
	//Now, check the existing questions for the parent...
	//(Each sub question should have the same parent)
	//Also check if any of these sequence questions are in another sequence.
	//Sometimes all but one question will be the same! Or the sequence could
	//be in a different order...
	$parent = array();
	$othersequences = array();		
	foreach($sqs_exist as $q) {
		$viable = $DB->get_record('question', array('id'=>$q));
		$parent[] = $viable->parent;
		//var_dump($parent);
		$othersequences[] = $DB->get_records_sql("SELECT question,sequence FROM {question_multianswer} WHERE sequence LIKE '%$q%' AND question != $question");
	}
	//var_dump($othersequences);
	
	//What are the distinct duplicate sequences?
	$distinctSequences = array();
	$otherQuestions = array();
	foreach($othersequences as $other => $os) {
		//var_dump($os);
		foreach($os as $ds) {
			$distinctSequences[] = $ds->sequence;
			$otherQuestions[] = $ds->question;
		}
	}
	$data['similar'] = array_unique($distinctSequences);	
	$data['otherQs'] = array_unique($otherQuestions);
	$data['otherCount'] = count($data['otherQs']);
	
	//If a parent question exists, 
	//check the database for the question info
	//and add each to the data array...

	if (!empty($parent)) { 
		$data['parent'] = implode(',', array_unique($parent));
		
		$record = $DB->get_record('question', array('id' =>$parent[0]));
		
		if(!empty($record)) {
		$data['pqname']	= $record->name;
 		$data['pqtext']	= plainText($record->questiontext);	
		}
	} 
	
	return $data;
}

//-------------------------------------------------------------------------------------------
//To fix the dodgy questions!
//-------------------------------------------------------------------------------------------
//Only run the below if it's been told to fix issues; don't want it for just info gathering
if (!empty($options['fix'])) {
	echo "Finding and stripping the HTML (except for img filenames) from multianswer questiontext for later use and storing it in a global variable called AllMulti...\n";
	$allmultianswer = $DB->get_records_sql("
				SELECT q.id,q.name,q.category,q.parent,q.questiontext,ma.sequence 
				FROM {question} q 
				JOIN {question_multianswer} ma ON q.id = ma.question");

	$result = count($allmultianswer);	
	
	//Strip all the HTML from the questiontext...
	foreach($allmultianswer as $multi) {
		$multi->questiontext = plainText($multi->questiontext);
	}

	//Need this globally available so it doesn't have to run for every single question!
	$GLOBALS["AllMulti"] = $allmultianswer;
	
	echo "Done. There are now [ $result ] questions to compare text against.\n";
	echo "Moving on...\n\n";
}
//-------------------------------------------------------------------------------------------
//If a sequence has no parent, and a bunch of missing sequence questions,
//update the database to make that sequence the same as the one in
//a question with the same question text...
//-------------------------------------------------------------------------------------------
function find_same_then_similar_questiontext($duplicate=null,$params=null) {
	global $DB;
	//var_dump($duplicate);
	
	//Create a few arrays to output messages to...
	$message1 = array();
	$success = array();
	$problem = array();
	$noseqnc = array();	
	//$resolved1 = array();
	//$unresolved1 = array();
	
	//$duplicate['parent'] may not exist, so can't assign it to a variable...
	//same for $duplicate['duplicateids']...
		
	if (empty($duplicate['parent'])) {
		//Checking/Updating each question separately is really slow, so do it based on the info of the first question.
		//Can be pretty sure if the questions all have the same sequence that they all have the same question text!
			
		$duplicates = $duplicate['duplicateids'];
		//Blow the array up into a comma separated list...
		$duplicatelist = implode("','", $duplicates);

		$matched = array();

		//Just use one question...
		$qToCheck = $duplicates[0];
		//var_dump($qToCheck);			

		//Now, get the info for this question...
		$qinfo 	   = $DB->get_record_sql("SELECT * FROM {question} WHERE id = '$qToCheck'");
		$qsequence = $DB->get_record_sql("SELECT * FROM {question_multianswer} WHERE question = '$qToCheck'");
		//var_dump($qinfo);
		
		
		//------------------------check for MATCHING questiontext-----------------------//
		
		echo "\nChecking to see if there is EXACTLY matching question text and a viable sequence to replace it with...\n";		
		//Record how long it takes...
		$starttime = microtime(true);
		//Find all the matching questions in the question table that have this name and text but are not in the duplicates list...
		$matching = $DB->get_records_sql("
							SELECT q.id,q.name,q.category,q.parent,q.questiontext,ma.sequence 
							FROM {question} q 
							JOIN {question_multianswer} ma ON q.id = ma.question 
							WHERE q.id NOT IN ('$duplicatelist') 
							AND q.questiontext = ?", array($qinfo->questiontext));		

		//So long as you have a match...
		if(!empty($matching)) {
			//Now see if one of those questions has a viable sequence in the multianswer table...
			//Just use the first one that has a viable sequence: no need to check the rest...			
			foreach ($matching as $check) {
				//var_dump($check);
				//If the sequence in $matching is not the same as the one for this question or 0...
				if($check->sequence !== $qsequence->sequence) {
				if($check->sequence !== '0') {
					$getinfo = get_sequence_info($check->sequence);
					//var_dump($getinfo);
					//If there are no sequence questions missing for this matching question
					// assign this sequence to $viablesequence and break the loop 
					// (don't need to check any others; the first one will do)....
					if(empty($getinfo['sqs_missing'])) {
						$viablesequence = $getinfo['sequence'];
						echo "Viable sequence found for question.id [ $qToCheck ] in question.id [ $check->id ]....\n";
						break;
					} else {
						$matchedDud[] = $check->id;
					}
				}} 	
			} 
		}
		else {
			echo "No matching questiontext found for question.id [ $qToCheck ]....\n";
		}
		
		if(!empty($matchedDud) && empty($viablesequence)) {
			echo "Matching text found in " . count($matchedDud) . " questions, but no viable sequence found for question.id [ $qToCheck ]\n";
		} 
		
		//var_dump($viablesequence);

		$endtime = microtime(true);
		$duration = $endtime - $starttime; //calculates total time taken
		echo "Query took $duration microseconds; \n\n";
		
			
		//If there are results for AllMulti and there were no matches from the above
		// search for no HTML matches now...
		if ($GLOBALS["AllMulti"] && empty($viablesequence)) {
			echo "\nChecking to see if there is matching NO HTML question text and a viable sequence to replace it with...\n";	
				
			//------------------------check for SIMILAR questiontext-----------------------//
			$plaintext = plainText($qinfo->questiontext);
			
			//var_dump($qinfo->questiontext);
			//var_dump($plaintext);	
						
			$allmulti = $GLOBALS["AllMulti"];
			//var_dump($GLOBALS["AllMulti"]);
			
			//Track how long this takes...
			$starttime = microtime(true);
			
			foreach($allmulti as $nohtml) {
				if($plaintext == $nohtml->questiontext) {					
					//If the sequence in $nohtml is not the same as the one for this question or 0...
					if($nohtml->sequence !== $qsequence->sequence) {
					if($nohtml->sequence !== '0') {
						$getinfo = get_sequence_info($nohtml->sequence);
						//var_dump($getinfo);
						$nohtmlqtext = $DB->get_record_sql("SELECT * FROM {question} WHERE id = '$nohtml->id'");
						//If there are no sequence questions missing for this matching question
						// assign this sequence to $viablesequence and break the loop 
						// (don't need to check any others; the first one will do)....
						if(empty($getinfo['sqs_missing'])) {
							$viablesequence = $getinfo['sequence'];							
							echo "Found a match for question.id [ $qToCheck ] in question.id [ $nohtml->id ]....!\n";
							echo "This question's stripped questiontext: [ $plaintext ]\n";
							echo "    The matched stripped questiontext: [ $nohtml->questiontext ]\n\n";
							echo "            The original questiontext: [ $qinfo->questiontext ]\n\n";
							echo "    The original matched questiontext: [ $nohtmlqtext->questiontext ]\n\n";
							echo "           Original sequence: [ $qsequence->sequence ]\n";
							echo "                New sequence: [ $viablesequence ]\n\n";
							break;
						} else {
							$noHTMLDud[] = $nohtml->id;
						}
					}}
				} 
			}
			
			//if there's no viable sequence, but there were matches...
			if(!empty($noHTMLDud) && empty($viablesequence)) {
				echo "Matching text found in " . count($noHTMLDud) . " questions, but no viable sequence found for question.id [ $qToCheck ]\n";
			} 
			
			//Stop timing...
			$endtime = microtime(true);
			$duration = $endtime - $starttime; //calculates total time taken
			echo "Query took $duration microseconds; \n\n";
		}
		
		
		
		//If there is a match with a viable sequence,
		// then assign these questions being fixed with the first sequence
		// that has viable questions. (That sequence will now have more duplicates,
		// but we'll fix that next). At least if we do this now, we'll know what 
		// the sequence q's have to be!
			
		//Now, set the sequence of this question to something
		//that actually has a question in the mdl_question table...
		foreach($duplicates as $d) {
			//Get the record to update...
			$missingSR = $DB->get_record_sql("SELECT * FROM {question_multianswer} WHERE question = '$d'");
			
			if(!empty($viablesequence)) {
				$missingSR->sequence = $viablesequence;
				//echo "A matching viable sequence exists: [ $viablesequence ]\n\n";
				if($DB->update_record('question_multianswer', $missingSR)) {
					$success[] = $d;
					$message1['success'] = $success;
					echo "Updated sequence for question.id $d to [ $viablesequence ]\n";
				} else {
					 $problem[] = $d;
					 $message1['problem'] = $problem;
					 echo "Failed to update record for question.id [ $d ]\n";
				}
			} else {
				$noseqnc[] = $qsequence->question;
				$message1['nosequence'] = $noseqnc;
				echo "Could not give a new sequence to question.id [ $d ]; none exist.\n";	
			} 
		}
		
		echo "------------------------------\n";

//							//If no viable sequence, try to delete the question!
//						    echo "Attempting to delete Question: ".$record->question."\n";
//							question_delete_question($record->question);
//							
//							//Now check to see if the question still exists in the DB...
//							$notify = $DB->get_record('question', array('id' =>$record->question));
//							//var_dump($notify);
//							//If it does, it wasn't deleted...
//							if (!empty($notify)) {
//						   		echo "Couldn't delete; question ".$record->question." may be in use\n";
//						   		$unresolved[] = $record->question;
//								$message['unresolved'] = $unresolved;
//						    } 
//						    else {
//								echo "Question ".$record->question." successfully deleted\n";
//						   		$resolved[] = $record->question;
//								$message['deleted'] = $resolved;						   		
//						    }   					

		return $message1;
		
	}
}
	
//-------------------------------------------------------------------------------------------
//For each question that DOES have sequence questions in the mdl_question table
//duplicate the sequence questions and assign the new ids to the sequence column
//ONLY if ALL sequence questions exist. If some are missing, it will stuff up!	
//-------------------------------------------------------------------------------------------
function has_parent_and_all_subquestions_fix($duplicate=null,$params=null) {
	global $DB;
	//var_dump($duplicate);
	
	//Create a few arrays to output messages to...
	$message2 = array();	
	$message2['newquestion'] = array();
		
	//Might not exist: $duplicates = $duplicate['duplicateids'];
	//Might not exist: $parent 	= $duplicate['parent'];
	$exists		= $duplicate['sqs_exist'];
	$missing 	= $duplicate['sqs_missing'];
	//$qstf = array();	
		
	if ( (!empty($duplicate['parent'])) && (empty($missing)) ) {
		
		$duplicates = $duplicate['duplicateids'];
		$parent = $duplicate['parent'];
	
		foreach($duplicates as $dupe) {		
		//var_dump($dupe);			
		//Do not change the parent question, just all the others...
		if($dupe != $parent) {
			//get the question category for the new questions
			$category = $DB->get_record_sql("SELECT category FROM {question} WHERE id = $dupe");
			//-------------------------------------------------------------------------------------------
			//Now, run through each sequence.question, 
			//duplicating each subquestion for this question...
			//(need to do each one like this so the new questions
			//are created in the right order)...
			//-------------------------------------------------------------------------------------------
			$insertquestion = array();	
			foreach($exists as $duplicateSQ) {
				echo "---------------------------------------------\n";
				echo "Duplicating sequence question [ $duplicateSQ ]\n";
				echo "---------------------------------------------\n\n";						
				$SQdata = $DB->get_record_sql("SELECT * FROM {question} WHERE id = $duplicateSQ");
				//var_dump($SQdata);													
				//Create a new question based on SQdata...
				$newquestion = array(
				'category' => $category->category,
				'parent' => $dupe,
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
				
				$newQ = $DB->insert_record('question', $newquestion);
				//var_dump($newQ);
				$insertquestion[] = $newQ;
				//var_dump($insertquestion);
				echo "\nAdded new question to the database with id: $newQ";
				//Add this to the success message...
				$message2['newquestion'] = $insertquestion;				
	
				//-------------------------------------------------------------------------------------------
				//Now, duplicate entries in mdl_question_answers...
				//-------------------------------------------------------------------------------------------
				$SQanswers = $DB->get_records_sql("SELECT * FROM {question_answers} WHERE question = ('$SQdata->id') ORDER BY id");
				//var_dump($SQanswer);
				//echo "---------------------------\n";							
				
				foreach($SQanswers as $SQanswer) {					
					$newQuestionA = array(
					'question' => $newQ,
					'answer' => $SQanswer->answer,
					'fraction' => $SQanswer->fraction,
					'feedback' => $SQanswer->feedback,
					'answerformat' => $SQanswer->answerformat,
					'feedbackformat' => $SQanswer->feedbackformat);
					
					$newQA = $DB->insert_record('question_answers', $newQuestionA);
					echo "\nAdded new question_answer to the database for Q [ $newQ ] with id: $newQA";
					//var_dump($newQA);
				}
				//echo "---------------------------\n";	
				
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
						echo "\nAdded new question_numerical to the database with id: $insertNumQ";	
					}
					}
				} 
				
				if ($SQdata->qtype = 'multichoice') {
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
					echo "\nAdded new qtype_multichoice_options to the database with id: $insertMCQ";
				}
				} 
				
				if ($SQdata->qtype = 'shortanswer') {					
					$shortanswerQ = $DB->get_record_sql("SELECT * FROM {qtype_shortanswer_options} WHERE questionid = ('$SQdata->id')");
					if(!empty($shortanswerQ)) {
					//var_dump($shortanswerQ);					
					$newSAQ = array(
					'questionid' => $newQ,
					'usecase' => $shortanswerQ->usecase);

					$insertSAQ = $DB->insert_record('qtype_shortanswer_options', $newSAQ);							
					echo "\nAdded new qtype_shortanswer_options to the database with id: $insertSAQ";
				}
				}
				
				echo "\n---------------------------\n";		
			}
			//-------------------------------------------------------------------------------------------
			//Assign the new array of sequence.questions 
			//to the sequence column of question.id....			
			//-------------------------------------------------------------------------------------------
			//First, get the new multianswer info...
			//var_dump($insertquestion);
			$QMA = $DB->get_record_sql("SELECT * FROM {question_multianswer} WHERE question = $dupe");

			echo "\nSequence for question [ $QMA->question ] was [ $QMA->sequence ]\n";
			
			$QMA->sequence = implode(',',$insertquestion);				
			if($DB->update_record('question_multianswer', $QMA)) {	
				echo "Updated sequence for  [ $QMA->question ] to  [ $QMA->sequence ]\n";
				$newsequence = $QMA->sequence;
				$message2['updated'] = $QMA->question;
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

			$attemptids = $DB->get_records_sql("SELECT id FROM {question_attempts} WHERE questionid = '$dupe'");
			//var_dump($attemptids);
			
			if (!empty($attemptids)) {					
			//Get the correct answers/values for the sequence question from mdl_question_answers
			//This will have the correct IDs needed for mdl_question_attempt_step_data...
			
			//var_dump($sqs);
			
			//Weed out any question that isn't multichoice as they are the only ones 
			//that will need fixing...
			$multichoice = array();
			foreach($newSQs as $nsq) {			
				$multichoice[] = $DB->get_records_sql("SELECT id FROM {question} WHERE qtype = 'multichoice' AND id = $nsq");
			}
			//var_dump($multichoice);
			
			$newValues = array();
			foreach($multichoice as $multi) {
				//var_dump($multi);
				if(!empty($multi)){
				foreach($multi as $MC) {
					$answers = $DB->get_records_sql("SELECT id,question FROM {question_answers} WHERE question = '$MC->id' ORDER BY question,id");
		//			echo "----------------------------------------\n";
		//			echo "Question Answer Group:\n";
		//			echo "----------------------------------------\n";
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
			}
	//		echo "----------------------------------------\n";
	//		echo "New values:\n";
	//		echo "----------------------------------------\n";
	//		var_dump($newValues);
			
			//Need to treat each attempt by itself, as some attempts might reference
			//   different sequence questions! (If the cloze question has been edited before)...
				
			foreach($attemptids as $attempt) {
				echo "----------------------------------------\n";
				echo "Attempt id: $attempt->id\n";
				echo "----------------------------------------\n";
				//var_dump($attempt);
				//Now get the attempt step ids for each of the question attempts where the state is todo.
				//(The three states are: todo, complete, graded-right/partial/invalid). We need todo.
				//We only need the id's, really...
				$steps = $DB->get_records_sql("SELECT id FROM {question_attempt_steps} WHERE state = 'todo' AND questionattemptID = $attempt->id");
				//var_dump($steps);
				
				
				//Need to treat each step separately...
				//echo "----------------------------------------\n";
				//echo "New Loop: ";
				//echo "----------------------------------------\n";
				foreach($steps as $step) {
				//var_dump($step);	
					$stepx = $step->id;
					
					//Now we need the step data so we can change the values in the value column.
					//But we only need the ones that are _sub?_order...
					$stepdata = $DB->get_records_sql("SELECT * FROM {question_attempt_step_data} WHERE name LIKE '%_order' AND attemptstepid = '$stepx' ORDER BY name");
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
	//				echo "----------------------------------------\n";
	//				echo "Old values:\n";
	//				echo "----------------------------------------\n";
	//				var_dump($oldValues);
					
					//Sort the oldValues from lowest->highest in their arrays...
					//(Some values are shuffled, which will ruin the replacement)
					$oldList = array();
					foreach($oldValuesArray as $ov) {
						sort($ov);
						$oldList[] = $ov;
					}
	//				echo "----------------------------------------\n";
	//				echo "Old values:\n";
	//				echo "----------------------------------------\n";
	//				var_dump($oldList);
					
					//Now we need to create an array where the oldValue is the key
					//And the newValue is the value (for the replacement function)...
					$newV = new ArrayIterator(array_values($newValues));		
					$oldV = new ArrayIterator(array_values($oldList));
						
					$result = new MultipleIterator;
					$result->attachIterator($oldV);
					$result->attachIterator($newV);
					
		//			echo "Result: ";
	//				var_dump($result);
					
					$replaceMArray = array();
					//Combine the two arrays (oldValue is the key, newValue is the value...
					foreach($result as $r) {
	//					echo "----------------------------------------\n";	
	//					echo "results as r :\n";
	//					echo "----------------------------------------\n";
	//					var_dump($r);
						//Make sure both arrays have the same number of elements
						//If not, it means some question-parts were deleted after attempts were made...
						if(sizeof($r[0]) == sizeof($r[1])) {
							$replaceMArray[] = array_combine($r[0],$r[1]);	
						} else {
							//This shouldn't happen now, but if it does...
							echo "---------------------------------------------------------------------------\n";
							echo "Error: Your replacement arrays are not equal for attempt: $attempt->id \n";
							echo "Make sure no sequence questions are missing for Q [ $dupe ] and try again. \n";
							echo "---------------------------------------------------------------------------\n";
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
		//				echo "----------------------------------------\n";
		//				echo "Replacement values:\n";
		//				echo "----------------------------------------\n";
		//				var_dump($replace);

						$result = array();
						foreach($oldValues as $old) {
							//var_dump($old);
							$result[] = strtr($old, $replace);
						}

		//				var_dump($oldValues);
		//				var_dump($result);		

						$subscount = sizeof($result);
						$y = 0;
						while($y < $subscount) {
							foreach($stepdata as $step => $item) {
		//						echo "original step: ";
		//						var_dump($step);
		//						echo "original item: ";
		//						var_dump($item);
								echo "Original step_data value for [ $item->id ] was [ $item->value ]\n";
								$item->value = $result[$y];
								$y++;
		//						echo "y = ";
		//						var_dump($y);
		//						echo "New item: ";
		//						var_dump($item);

								if($DB->update_record('question_attempt_step_data', $item)) {	
									echo "Updated  step_data value for [ $item->id ] now [ $item->value ]\n";
								} else {
									echo "Couldn't update record";
								}		
							}
						}
					}		
				}
			}			
		}
		}		
		echo "next-----------------------\n";
		}
	}
	return $message2;
}
/*----------------------------------/
//----- What actually gets run -----/
/----------------------------------*/

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
		echo "No questions have only commas for a sequence. This is a good thing...\n\n";
	}
}

//Get ALL the questions, or just a single question based 
//on what you specified when running the php file...
if($options['questions']) {
	$sequences1 = $DB->get_recordset_sql('SELECT DISTINCT(sequence) FROM {question_multianswer} '. $where, $params);	
//var_dump($sequences);
} else if($options['course']) {
	$sqcourseqs1 = $DB->get_recordset_sql('
	SELECT DISTINCT(sequence) 
	FROM {question_multianswer} qm
	JOIN {question} q ON qm.question = q.id
	JOIN {question_categories} qc ON q.category = qc.id
	JOIN {context} ctxt ON qc.contextid = ctxt.id
	JOIN {course} c ON ctxt.instanceid = c.id '. $where, $params);
	//var_dump($sqcourseqs1);
}
//Put the info into an array...
$print = array();

///////////////////////////////////////////////
//If you have specified a question....
///////////////////////////////////////////////
if($options['questions']) {
	
if (!empty($options['fix'])) {
	echo "-----------------------------------------------------------------------------\n";
	echo "Attempting to match questions with no parent with other same-text questions. \n";
	echo "If successful, will assign that sequence to this question.\n";
	echo "If none with the same exist, check for similiar and tell me the question.id\n";
	echo "-----------------------------------------------------------------------------\n";
}
	
$nosequence = array();
$noupdate = array();
$updated = array();

//Run the find_similar function first...
foreach ($sequences1 as $sequence1) {	
	//var_dump($sequence);	
	//dodgy sequence = sequence from sql
	$ds = $sequence1->sequence;
	//var_dump($ds);
	$getinfo = get_sequence_info($ds, empty($options['info']));	
	$print[] = $getinfo;
	
	if($getinfo) {		
		if (!empty($options['info'])) {
		//var_dump($getinfo);			
			
			$info="\n//////////////////////////////////////////////////////////////////\n"
				 ."----- Sequence $getinfo[sequence] info -----\n"
				 ."//////////////////////////////////////////////////////////////////\n\n"
		 		 ."[ No. of questions using this sequence ]: $getinfo[duplicatecount]\n"
				 ."[                  Those questions are ]: ";
		
		foreach($getinfo['duplicateids'] as $duplicates) {
			$info .= $duplicates;
			$info .= " ";					
		}
			
			$info .="\n\n[ The following questions have been attempted ]: ";			
		if(!empty($getinfo['attempted'])) { foreach($getinfo['attempted'] as $a) {
			$info .= $a;
			$info .= " ";	
		}} 
			$info .="\n\n/////////////////////////////////////";
		if(!empty($getinfo['sqs_exist'])) {
			$info .="\n\n[ SQs exist ]: ".implode(',', $getinfo['sqs_exist']);
		} 
		if(!empty($getinfo['sqs_missing'])) {
			$info .="\n[ SQs MISSING ]: ".implode(',', $getinfo['sqs_missing']);
		}
		
		
		if((!empty($getinfo['similar'])) && ($getinfo['similar'][0] != $getinfo['sequence'])) { 
			$info .="\n\n/////////////////////////////////////";
			$info .="\n\n[ similar sequences ]: ";
			foreach($getinfo['similar'] as $sim) {
					$info .= $sim;
					$info .= " | ";
			}
		
			$info .= "\n\n[ No. of question using this other sequence ]: $getinfo[otherCount]";
			$info .= "\n[ questions for similar sequences ]: ";
			if(!empty($getinfo['otherQs'])) { foreach($getinfo['otherQs'] as $oq) {
					$info .= $oq;
					$info .= " ";
			}}
		
			$info .="\n\n/////////////////////////////////////";
		}
			if(!empty($getinfo['parent'])) {
			$info .="\n\n[ parent.q ]: $getinfo[parent]\n" 
				   ."[  pq.name ]: $getinfo[pqname]\n"
				   ."[  pq.text ]: $getinfo[pqtext]\n";
			} else {
			$info .="\n\n[ parent.q ]: NULL\n" 
				   ."[  pq.name ]: NULL\n"
				   ."[  pq.text ]: NULL\n";
			}
			
//			$info .="\n\n[ quiz names ]:\n";
//			if(!empty($getinfo['quiz'])) { foreach($getinfo['quiz'] as $qz) {
//				$info .= "...";
//				$info .= $qz;
//				$info .="\n";
//			}}			 
			
		if(!empty($getinfo['courseid']) && !empty($getinfo['quiz']) && !empty($getinfo['attempted'])) {
			$info .="\n//////////////////////////\n";
			$info .="\n\nInfo for just the attempted questions..."; 
			$info .="\n[ questionid : courseid : coursename : quiz ]:\n\n";	
			
			$attmptd = new ArrayIterator(array_values($getinfo['attempted']));
			$c_id = new ArrayIterator(array_values($getinfo['attemptedCourseID']));
			$c_name = new ArrayIterator(array_values($getinfo['attemptedCourseName']));
			$qz = new ArrayIterator(array_values($getinfo['attemptedQuiz']));						
			
			$courseinfos = new MultipleIterator;
			$courseinfos->attachIterator($attmptd);
			$courseinfos->attachIterator($c_id);
			$courseinfos->attachIterator($c_name);
			$courseinfos->attachIterator($qz);			
						
			$courseinfo = array();
			foreach($courseinfos as $cinfo) {
				$courseinfo[] = $cinfo[0].' --:-- '.$cinfo[1].' --:-- '.$cinfo[2].' --:-- '.$cinfo[3];
			} 
			//var_dump($courseinfo);
			
			foreach($courseinfo as $ci) {
				$info .= "...";
				$info .= $ci;
				$info .="\n\n";	
			}
		} 
		if (!empty($getinfo['courseid'])) {
			$info .="\n//////////////////////////\n";
			$info .="\n[ Questions that have NOT been attempted in any quizzes, but do appear in these courses and categories...]\n";	
			$info .="\n[ question id : course id : course name : category...]\n\n";
			
			$q_id = new ArrayIterator(array_values($getinfo['duplicateids']));
			$c_id = new ArrayIterator(array_values($getinfo['courseid']));
			$c_name = new ArrayIterator(array_values($getinfo['course']));
			$q_cat = new ArrayIterator(array_values($getinfo['category']));			
			
			$courseinfos = new MultipleIterator;
			$courseinfos->attachIterator($q_id);
			$courseinfos->attachIterator($c_id);
			$courseinfos->attachIterator($c_name);
			$courseinfos->attachIterator($q_cat);
			
			$courseinfo = array();
			foreach($courseinfos as $cinfo) {
				$courseinfo[] = $cinfo[0].' --:-- '.$cinfo[1].' --:-- '.$cinfo[2].' --:-- '.$cinfo[3];
			} 
			//var_dump($courseinfo);
			
			foreach($courseinfo as $ci) {
				$info .= "...";
				$info .= $ci;
				$info .="\n\n";	
			}

		} else {
			$info .="No course information?!\n\n";	
		}
			$info .="\n"
				  ."-------------------------------------------------------------------\n\n";
			echo $info;
			
		}  
		else if (!empty($options['fix'])) {
			//var_dump($getinfo);	
			
			$fix = find_same_then_similar_questiontext($getinfo, empty($options['fix']));
			//var_dump($fix);
			
			if(!empty($fix)) {				
				if(!empty($fix['nosequence'])) {
					foreach($fix['nosequence'] as $no) {
						$nosequence[] = $no;
					}
				}
				if(!empty($fix['problem'])) {
					foreach($fix['problem'] as $problem) {
						$noupdate[] = $problem;
					}
				}
				if(!empty($fix['success'])) {
					foreach($fix['success'] as $success) {
						$updated[] = $success;
					}
				}
			} 
		}  
	}	
}
if(empty($nosequence) || !empty($noupdate) || !empty($updated)) {
	echo "no info...\n\n"; 
}

$sequences1->close();

//Now get the updated info...
$sequences2 = $DB->get_recordset_sql('SELECT DISTINCT(sequence) FROM {question_multianswer} '. $where, $params);
//Now run the fix for questions with a parent and all subquestions...
if (!empty($options['fix'])) {
	echo "\n\n-----------------------------------------------------------------------------------\n";
	echo "Attempting to fix questions that have a parent and all subquestions. \n";
	echo "If successful, will duplicate subquestions and assign new sequence and fix attempts.\n";
	echo "------------------------------------------------------------------------------------\n";

$newquestion = array();	
	
foreach ($sequences2 as $sequence2) {	
	//var_dump($sequence);	
	//dodgy sequence = sequence from sql
	$ds = $sequence2->sequence;
	//var_dump($ds);
	$getinfo = get_sequence_info($ds, empty($options['info']));	
	$print[] = $getinfo;
	
	if($getinfo && !empty($options['fix'])) {
		//var_dump($getinfo);
		
		$fix = has_parent_and_all_subquestions_fix($getinfo, empty($options['fix']));
		//var_dump($fix);		

		if(!empty($fix)) {
			if(!empty($fix['newquestion'])) {
				foreach($fix['newquestion'] as $new) {
					$newquestion[] = $new;
				}
			}
		} 
	}	
}}
if(!empty($options['fix'])) {
	if(!empty($fixed)) {
		echo "Nothing to fix...\n\n";
	}
}
	
$sequences2->close();

echo "\n-------------------------------------------------------------------\n";
echo "Done!\n";
echo "-------------------------------------------------------------------\n\n";
echo "\n---------------------------------------------\n"
	." ||| Found $sequencescount problem sequences(s)... |||\n"
	."---------------------------------------------\n";
}


///////////////////////////////////////////////
//If you have specified a question....
///////////////////////////////////////////////
if($options['course']) {
	
if (!empty($options['fix'])) {
echo "-----------------------------------------------------------------------------\n";
echo "Attempting to match questions with no parent with other same-text questions. \n";
echo "If successful, will assign that sequence to this question.\n";
echo "If none with the same exist, check for similiar and tell me the question.id\n";
echo "-----------------------------------------------------------------------------\n";
}
$nosequence = array();
$noupdate = array();
$updated = array();

foreach ($sqcourseqs1 as $courseq1) {	
	//var_dump($sequence);	
	//dodgy sequence = sequence from sql
	$ds = $courseq1->sequence;
	//var_dump($ds);
	$getinfo = get_sequence_info($ds, empty($options['info']));	
	$print[] = $getinfo;
	
	if($getinfo) {		
		if (!empty($options['info'])) {
		//var_dump($getinfo);			
			
			$info="\n//////////////////////////////////////////////////////////////////\n"
				 ."----- Sequence $getinfo[sequence] info -----\n"
				 ."//////////////////////////////////////////////////////////////////\n\n"
		 		 ."[ No. of questions using this sequence ]: $getinfo[duplicatecount]\n"
				 ."[                  Those questions are ]: ";
		
		foreach($getinfo['duplicateids'] as $duplicates) {
			$info .= $duplicates;
			$info .= " ";					
		}
			
			$info .="\n\n[ The following questions have been attempted ]: ";			
		if(!empty($getinfo['attempted'])) { foreach($getinfo['attempted'] as $a) {
			$info .= $a;
			$info .= " ";	
		}} 
			$info .="\n\n/////////////////////////////////////";
		if(!empty($getinfo['sqs_exist'])) {
			$info .="\n\n[ SQs exist ]: ".implode(',', $getinfo['sqs_exist']);
		} 
		if(!empty($getinfo['sqs_missing'])) {
			$info .="\n[ SQs MISSING ]: ".implode(',', $getinfo['sqs_missing']);
		}
		
		
		if((!empty($getinfo['similar'])) && ($getinfo['similar'][0] != $getinfo['sequence'])) { 
			$info .="\n\n/////////////////////////////////////";
			$info .="\n\n[ similar sequences ]: ";
			foreach($getinfo['similar'] as $sim) {
					$info .= $sim;
					$info .= " | ";
			}
		
			$info .= "\n\n[ No. of question using this other sequence ]: $getinfo[otherCount]";
			$info .= "\n[ questions for similar sequences ]: ";
			if(!empty($getinfo['otherQs'])) { foreach($getinfo['otherQs'] as $oq) {
					$info .= $oq;
					$info .= " ";
			}}
		
			$info .="\n\n/////////////////////////////////////";
		}
			if(!empty($getinfo['parent'])) {
			$info .="\n\n[ parent.q ]: $getinfo[parent]\n" 
				   ."[  pq.name ]: $getinfo[pqname]\n"
				   ."[  pq.text ]: $getinfo[pqtext]\n";
			} else {
			$info .="\n\n[ parent.q ]: NULL\n" 
				   ."[  pq.name ]: NULL\n"
				   ."[  pq.text ]: NULL\n";
			}
			
//			$info .="\n\n[ quiz names ]:\n";
//			if(!empty($getinfo['quiz'])) { foreach($getinfo['quiz'] as $qz) {
//				$info .= "...";
//				$info .= $qz;
//				$info .="\n";
//			}}			 
			
		if(!empty($getinfo['courseid']) && !empty($getinfo['quiz']) && !empty($getinfo['attempted'])) {
			$info .="\n//////////////////////////\n";
			$info .="\n\nInfo for just the attempted questions..."; 
			$info .="\n[ questionid : courseid : coursename : quiz ]:\n\n";	
			
			$attmptd = new ArrayIterator(array_values($getinfo['attempted']));
			$c_id = new ArrayIterator(array_values($getinfo['attemptedCourseID']));
			$c_name = new ArrayIterator(array_values($getinfo['attemptedCourseName']));
			$qz = new ArrayIterator(array_values($getinfo['attemptedQuiz']));						
			
			$courseinfos = new MultipleIterator;
			$courseinfos->attachIterator($attmptd);
			$courseinfos->attachIterator($c_id);
			$courseinfos->attachIterator($c_name);
			$courseinfos->attachIterator($qz);			
						
			$courseinfo = array();
			foreach($courseinfos as $cinfo) {
				$courseinfo[] = $cinfo[0].' --:-- '.$cinfo[1].' --:-- '.$cinfo[2].' --:-- '.$cinfo[3];
			} 
			//var_dump($courseinfo);
			
			foreach($courseinfo as $ci) {
				$info .= "...";
				$info .= $ci;
				$info .="\n\n";	
			}
		} 
		if (!empty($getinfo['courseid'])) {
			$info .="\n//////////////////////////\n";
			$info .="\n[ Questions that have NOT been attempted in any quizzes, but are in these courses and categories...]\n";	
			$info .="\n[ question id : course id : course name : category...]\n\n";
			
			$q_id = new ArrayIterator(array_values($getinfo['duplicateids']));
			$c_id = new ArrayIterator(array_values($getinfo['courseid']));
			$c_name = new ArrayIterator(array_values($getinfo['course']));
			$q_cat = new ArrayIterator(array_values($getinfo['category']));			
			
			$courseinfos = new MultipleIterator;
			$courseinfos->attachIterator($q_id);
			$courseinfos->attachIterator($c_id);
			$courseinfos->attachIterator($c_name);
			$courseinfos->attachIterator($q_cat);
			
			$courseinfo = array();
			foreach($courseinfos as $cinfo) {
				$courseinfo[] = $cinfo[0].' --:-- '.$cinfo[1].' --:-- '.$cinfo[2].' --:-- '.$cinfo[3];
			} 
			//var_dump($courseinfo);
			
			foreach($courseinfo as $ci) {
				$info .= "...";
				$info .= $ci;
				$info .="\n\n";	
			}

		} else {
			$info .="No course information?!\n\n";	
		}
			$info .="\n"
				  ."-------------------------------------------------------------------\n\n";
			echo $info;
			
		}  
		else if (!empty($options['fix'])) {
			//var_dump($getinfo);	
			
			$fix = find_same_then_similar_questiontext($getinfo, empty($options['fix']));
			var_dump($fix);
			
			if(!empty($fix)) {				
				if(!empty($fix['nosequence'])) {
					foreach($fix['nosequence'] as $no) {
						$nosequence[] = $no;
					}
				}
				if(!empty($fix['problem'])) {
					foreach($fix['problem'] as $problem) {
						$noupdate[] = $problem;
					}
				}
				if(!empty($fix['success'])) {
					foreach($fix['success'] as $success) {
						$updated[] = $success;
					}
				}
			} 
		}  
	}	
}
if(!empty($options['fix'])) {
if(empty($nosequence) || !empty($noupdate) || !empty($updated)) {
	echo "no info...\n\n"; 
}}

$sqcourseqs1->close();

//Now get the updated info...
$sqcourseqs2 = $DB->get_recordset_sql('
	SELECT DISTINCT(sequence) 
	FROM {question_multianswer} qm
	JOIN {question} q ON qm.question = q.id
	JOIN {question_categories} qc ON q.category = qc.id
	JOIN {context} ctxt ON qc.contextid = ctxt.id
	JOIN {course} c ON ctxt.instanceid = c.id '. $where, $params);
//Now run the fix for questions with a parent and all subquestions...
if (!empty($options['fix'])) {
echo "\n\n-----------------------------------------------------------------------------------\n";
echo "Attempting to fix questions that have a parent and all subquestions. \n";
echo "If successful, will duplicate subquestions and assign new sequence and fix attempts.\n";
echo "------------------------------------------------------------------------------------\n";

	
$fixed = NULL;	
foreach ($sqcourseqs2 as $courseqs2) {	
	//var_dump($sequence);	
	//dodgy sequence = sequence from sql
	$ds = $courseqs2->sequence;
	//var_dump($ds);
	$getinfo = get_sequence_info($ds, empty($options['info']));	
	$print[] = $getinfo;
	
	if($getinfo && !empty($options['fix'])) {
		//var_dump($getinfo);
		
		$fix = has_parent_and_all_subquestions_fix($getinfo, empty($options['fix']));
		//var_dump($fix);		

		if(!empty($fix)) {
			if(!empty($fix['newquestion'])) {
				foreach($fix['newquestion'] as $new) {
					$newquestion[] = $new;
				}
			}
		}
	}	
}}
if(!empty($options['fix'])) {
	if(!empty($fixed)) {
		echo "Nothing to fix...\n\n";
	}
}

$sqcourseqs2->close();

echo "\n-------------------------------------------------------------------\n";
echo "Done!\n";
echo "-------------------------------------------------------------------\n\n";
echo "\n---------------------------------------------------------------\n"
	." ||| Found $sqcoursecount multianswer question(s) in this course... |||\n"
	."---------------------------------------------------------------\n";
}



//var_dump($print);
//Put this info in a file if you want to keep it...
//was hoping this would break the file up into separate ones, but it didn't...
function writeToCSV($array) {
    $i = 1;
    $j = 1;
    $fp = fopen('badsequences' . $j . '.csv', 'w');
	fputcsv($fp, array('sequence',"\t",'DupeQs',"\t",'DupeCount',"\t",'attempted',"\t",'categories',"\t",'courseids',"\t",'courses',"\t",'quiz',"\t",'SequencesExist',"\t",'SequencesMissing',"\t",'ParentQ',"\t",'PQname',"\t",'PQtext'));
	$printthis = array();
    foreach($array as $fields => $value) {
		foreach($value as $gimme => $more) {
			$printthis[] = json_encode($more);
		}
		$printthis[] .= "\n";
    }
	if ($i % 1000 == 0) {
		fclose($fp);
		$fp = fopen('badsequences' . $j . '.csv', 'w');
		$j = $j + 1;
	}
	fputcsv($fp, $printthis, "\t");
	$i = $i + 1;
    fclose($fp);
}
//writeToCSV($print);

//Just get the DISTINCT courses that will be affected...
function writeToCSV2($array) {
    $i = 1;
    $j = 1;
    $fp = fopen('affectedcourses' . $j . '.csv', 'w');
	$printthis = array();
    foreach($array as $fields) {
		$course = $fields['course'];
		if(!empty($course)) {
		foreach($course as $gimme => $value) {
			$printthis[] = $value ."\n";
		}
		}
		//var_dump($printthis);
    }
	//var_dump($printthis);
	$printthis[] .= "\n";
	if ($i % 1000 == 0) {
		fclose($fp);
		$fp = fopen('affectedcourses' . $j . '.csv', 'w');
		$j = $j + 1;
	}
	fputcsv($fp, $printthis, "\t");
	$i = $i + 1;
    fclose($fp);
}
//writeToCSV2($print);

//$showmeinfo = array();

if(!empty($options['fix'])) {
	echo "\n";	
	
	echo "Fails:\n";
	echo "...[".count($nosequence)."] questions had no viable sequences to copy\n";
	if(!empty($nosequence1)) {foreach($nosequence1 as $nofix) {echo "... ".$nofix. "\n";}}
	//echo "\n...Deleted [".count($deleted)."] questions that had no viable sequence.\n";
	echo "...[".count($noupdate)."] questions failed to have a new sequence applied\n\n";
	
	echo "Successes:\n";
	echo "...[".count($updated)."] questions had new sequences applied\n";
	echo "...[".count($newquestion)."] new sub/sequence questions were added to the mdl_question table.\n";
	//echo "...There are still [".count($unresolved)."] questions that need to be corrected.\n";
	echo "\n\n---------------------------------------------------\n\n";
} else if (!empty($options['info'])) {
	//foreach ($showmeinfo as $info) {
					
	//};
} else {
	echo "...To fix all corrupt questions, run: \$sudo -u wwwrun php ./fix_course_sequence.php --questions=* --fix". "\n\n"
		."...To fix a specified question,  run: \$sudo -u wwwrun php ./fix_course_sequence.php --questions=[type in questionid] --fix". "\n\n";
}

?>
