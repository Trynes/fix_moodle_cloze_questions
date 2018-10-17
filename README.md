# fix_moodle_cloze_questions
An admin/cli script to fix corrupt Moodle cloze questions

----------------------------------------------------------------------------------------------
DISCLAIMER: 
This is not a Moodle-sanctioned script! Moodle.org have nothing to do with this.
----------------------------------------------------------------------------------------------
This script works for Moodle 3.3.6, with a Postgres 9 database. You can test it on other systems, but I give no guarantees that it will work as expected.<br>
I highly suggest testing it first on a copy of your system to ensure it doesn't corrupt your whole questions database,
not that I think it should! I've run it against a few courses in our system and nothing untoward has happened so far!

I suggest, if you DO use it on your production instance, that you also test it against a few courses first.
NOTE: if you fix ONE course, it will likely affect other courses, since that is the nature of the beast!

----------------------------------------------------------------------------------------------
IMPORTANT: This script will run for a long time on big systems if called for all questions.
Run ONLY while in maintenance mode as another user may simultaneously edit one of the 
questions being checked/fixed and cause more problems.
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
<strong>To fix this, the script will do the following...</strong>

<b>1.</b> Find all questions in the mdl_question_multianswer table in the DB for incorrect/invalid sequences; 
i.e. sequence is null, or ',,,,' (no subquestions listed, or only commas).
<br>
If any questions have an EMPTY/INVALID sequence, it will update the sequence to '0'.
This is to ensure courses don't fail on import when it can't find the seqeunce questions.
<br>

<b>2.</b> Find and strip ALL HTML (except for img filenames) from multianswer questiontext for later use (used to check if the same/similar questiontext exists in other questions).
<br>

<b>3a.</b> If ALL sequence questions exist, it will duplicate those sequence questions and then assign those new ids to the main questions that are NOT the parent of the original sequence questions. This will make all those results in the first SQL example above look like the results from the second example SQL.
<br>

<b>3b.</b> If NONE of the sequence questions exist, it will look for the same, & then stripped, questiontext within the database to see if there are any questions that match and DO have sequence questions. If it finds a matching question, it will assign question A the sequence of question B (the one it just matched to). Once that is done, it will complete step 3a.
<br>

<b>4.</b> Once the questions all have new sequence questions (and answers in the mdl_question_answers table), it will then go through and fix any attempts that might have been made on these questions, assigning the new sequence question answers to the attempts so that you don't get 'the answer was changed after attempt' (or whatever the message is).
<br>

<b>5.</b> TO BE WRITTEN: If there are no matches with other questions, meaning you now have a main question with no sequence questions, and just get {#1}, {#2}, in your main question instead of {1:SA:=bla}..... I'm not sure yet!
<br>

----------------------------------------------------------------------------------------------
IMPORTANT REMINDER: This script will run for a long time on big systems if called for all 
questions. Run ONLY while in maintenance mode, or for a particular course that you know 
will not be edited while running the script, as another user may simultaneously edit one 
of the questions being checked/fixed and cause more problems.
----------------------------------------------------------------------------------------------
<br>

<b>HOW TO USE:</b>

1. Copy the file to moodle/admin/cli; 

<b>From the server console:</b>

2. You can get information on QUESTIONS that might have more than one sequence match by running:
    
    <b>ALL questions</b>
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=* --info

    <b>a few</b> questions:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --info

    <b>a single</b> question:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --info
  
3. Now, to run the fix:
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=* --fix
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456,223344,445566 --fix
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --questions=123456 --fix

<br>
OR, if you would like to check a COURSE in particular:

2. You can get information on COURSES that might have more than one sequence match by running:
    
    <b>ALL courses</b>
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=* --info

    <b>a few</b> ourses:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456,223344,445566 --info

    <b>a single</b> ourses:
    <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456 --info
  
3. Now, to run the fix:
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=* --fix
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456,223344,445566 --fix
  <br>sudo -u www-data /usr/bin/php admin/cli/fix_multianswer_sequences.php --course=123456 --fix
