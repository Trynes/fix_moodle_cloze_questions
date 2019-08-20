# fix_moodle_cloze_questions
An admin/cli script to fix corrupt Moodle cloze questions


----------------------------------------------------------------------------------------------
IMPORTANT: Script is mostly complete. Running full 'fix' now. Will get back with results soon.
----------------------------------------------------------------------------------------------


----------------------------------------------------------------------------------------------
DISCLAIMER: 
This is not a Moodle-sanctioned script! Moodle.org have nothing to do with this.
----------------------------------------------------------------------------------------------

This script works for Moodle 3.3.9, with a Postgres 9 database. You can test it on other systems, but I give no guarantees that it will work as expected. I highly suggest testing it first on a copy of your system to ensure it doesn't corrupt your whole questions database, not that I think it should! 

----------------------------------------------------------------------------------------------
IMPORTANT: This script will run for a long time on big systems if called for all questions.
I suggest you run it while in maintenance mode as another user may simultaneously edit one of the 
questions being checked/fixed and cause more problems, but it's not essential.
----------------------------------------------------------------------------------------------

This is a multi-step script.
<br><br>
<strong>THE PROBLEM:</strong>

The SQL below shows all the questions with a sequence of '2783831,2783832,2783833'
<br>

SELECT question,sequence
FROM mdl_question_multianswer
WHERE sequence = '2783831,2783832,2783833';
<br><br>
 question | ------- sequence ---------- |<br>
----------+-----------------------------+<br>
  5334552 | 2783831,2783832,2783833<br>
  3756523 | 2783831,2783832,2783833<br>
  3758257 | 2783831,2783832,2783833<br>
  6473599 | 2783831,2783832,2783833<br>
  7302100 | 2783831,2783832,2783833<br>
  9878958 | 2783831,2783832,2783833<br>
<br>

Ideally, there should only be ONE result for this sequence:
<br><br>
 question | ------- sequence ---------- |<br>
----------+-----------------------------+<br>
  3758257 | 2783831,2783832,2783833 |<br>

<br>
If you looked at the mdl_question result for the first sequence question '2783831', you would see that the parent is one of the questions above:
<br>

--- id -- |  parent  | <br>
----------+----------+ <br>
  2783831 | 3758257<br>
<br>

If all the questions in the first column were <b>correct</b>, however, they would look more like this<br>
(taking just the first question in the sequence as an example):
<br><br>
 question | ------- qma.sequence ------- | --- q.id --- | q.parent<br>
----------+-----------------------------+----------+---------<br>
  5334552 | 5334553,5334554,5334555 | 5334553 | 5334552<br>
  3756523 | 3756524,3756525,3756526 | 3756524 | 3756523<br>
  3758257 | 2783831,2783832,2783833 | 2783831 | 3758257<br>
  6473599 | 6473600,6473601,6473602 | 6473600 | 6473599<br>
  7302100 | 7302101,7302102,7302103 | 7302101 | 7302100<br>
  9878958 | 9878959,9878960,9878961 | 9878959 | 9878958<br>

<br><br>
<b>Why is this a problem?</b> Because if someone deletes the parent question '3758257', every other question with the sequence '2783831,2783832,2783833' will now have NO sequence questions listed and this will cause an error within Moodle! If you check the mdl_question_multianswer table for the question, you will find it now likely has a sequence of ',,' instead.

<br><br>
<strong>To fix this, version 2 (ICT_fix_multianswer_question_v2.php) of the script will do the following...</strong>

<b>1.</b> Find all questions in the mdl_question_multianswer table in the DB for incorrect/invalid sequences; 
i.e. sequence is null, or ',,,,' (no subquestions listed, or only commas). If any questions have an EMPTY/INVALID sequence, it will update the sequence to '0'. Don't worry about that though, it will be fixed shortly. It's mostly just to stop courses failing import while the fix is running (if you're doing it while not in maintenance mode).
<br>

<b>2.</b> Find and strip ALL HTML (except for img filenames) from multianswer questiontext and then stick all of the results into an array, grouped by stripped questiontext. This is helpful when running the fix.
<br>

<b>3a.</b> If ALL sequence questions exist, and the question has equal cloze placeholders to the amount of sequence questions there are, it will duplicate those sequence questions and then assign those new ids to the main questions that are NOT the parent of the original sequence questions. This will make all those results in the first SQL example above look like the results from the second example SQL.
<br>

<b>3b.</b> If NONE of the sequence questions exist, it will see if this same question text has a viable result (easy, because the questions are grouped by question text), and will then replace the sequence with a working one. It will then go through step 3a.
<br>

<b>4.</b> Once the questions all have new sequence questions (and answers in the mdl_question_answers table, and matching questions in the numerical, multichoice, and short answer tables), it will then go through and fix any attempts that might have been made on these questions, assigning the new sequence question answers to the attempts so that you don't get 'the answer was changed after attempt' (or whatever the message is).
<br>

<b>5.</b> Bingo, presto, all is good. You can tee the results into an .html file that is fairly easy to read. The file might need to be manually broken into separate files, however. Mine was a very large file! I've included an example of what that html file will look like in the files area.

----------------------------------------------------------------------------------------------
IMPORTANT REMINDER: This script will run for a long time on big systems if called for all 
questions. 
----------------------------------------------------------------------------------------------
<br>

<b>HOW TO USE:</b>

1. Copy the file to moodle/admin/cli; 

<b>From the server console:</b>

2. You can get information on the multianswer QUESTIONS by running (subsititute whatever parts you need to fit your system. This was run on a machine running SUSE/Linux):
    
    <b>ALL questions, tee into a html file and show on screen at the same time...</b>
    <br>sudo -u www-data /usr/bin/php admin/cli/ICT_fix_multianswer_questions_v2.php --questions=* --info --verbose 2>&1 | tee INFO_VERBOSE_Multianswer_ALL.html

    <b>a few</b> questions:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --info --verbose

    <b>a single</b> question:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --info --verbose
    
    <b>only broken</b> questions (leave out --verbose):
    <br>sudo -u www-data /usr/bin/php admin/cli/ICT_fix_multianswer_questions_v2.php --questions=* --info 2>&1 | tee INFO_Multianswer_BROKEN_ONLY.html
  
3. Now, to run the fix:

(This will output the info part as well just for reference. It will do this twice. Once to show the intial information, and then again to show the updated information. Remember, you can leave off the --verbose part to just show the broken questions):

----------------------------------------------------------------------------------------------
IMPORTANT: If you run the fix for just 1 question that needs a more viable sequence first, 
you will need to include the question id of another question that has a viable sequence.
If you run it for 1 question that just has the wrong parent only, it will work fine.
----------------------------------------------------------------------------------------------

  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=* --fix --verbose
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --fix --verbose
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --fix --verbose

If you want to tee it into a file, just add: 2>&1 | tee FIX_VERBOSE_Multianswer_ALL.html

If you have any question/comments, feel free to create an issue (if you can). Or email me. I assume that info is on here somewhere?
