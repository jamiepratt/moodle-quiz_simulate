A Moodle Quiz Report Plug in For Simulating Student Quiz Attempts
=================================================================

This plug in was developed to aid testing of the quiz statistics. It allows you to upload a csv file with information about
student attempts, the users are then created and enrolled in the course if necessary and then Moodle evaluates there responses to
the quiz questions and grades and stats are calculated as they would be for real student attempts.

##Compatibility

Works with Moodle 2.6+

##Installation

###Manually

To install, either [download the zip file](https://github.com/jamiepratt/moodle-quiz_simulate/archive/master.zip),
unzip it, and place it in the directory `moodle\mod\quiz\report\simulate`.
(You will need to rename the directory `quiz_simulate -> simulate`.)

###Using git

Alternatively, get the code using git by running the following command in the
top level folder of your Moodle install:

    git clone https://github.com/jamiepratt/moodle-quiz_simulate.git mod/quiz/report/simulate

##Database initialisation

Whether installed from git or manually you need to go to http://{yourmoodlerooturl}/admin/ to instruct Moodle to install the
plug-in.

##Usage

See the example quiz back-ups (.mbz) and response data (.csv) files in the mod/quiz/report/simulate/examples/ directory.

For the examples in each sub directory :

1. The quiz back up is a normal Moodle backup file that can be restored into a course using the restore link on the course
administration menu.
2. and the response data file can be used to simulate responses by students to the quiz. Once you have created the quiz go to
'Reasults -> Simulate' in the Quiz you created's Quiz Administration menu.

###Format of Response Data CSV file

The format of the files is pretty self explanatory, field column names are as follows :
following :

- quizattempt : each separate attempt at a quiz has a unique number. If two rows have the same number then this is a
continuation
of the quiz attempt (for multiple tries see also column 'finished').
- firstname,lastname : name of the student whose attempt this is, if a matching record doesn't exist then it is created. If the
student is not enrolled they are enrolled.
- randqs.{slot no} : this can only be used for a random question in a quiz. You can use this field to override the random selection
 of a question and specify what question the student saw.
- variants.{slot no} : used in a slot with a question with variants, this would override the random selection of which variant the
  student saw.
- responses.{slot no}.{response field name} This column is used to specify what the student entered in the question. For some
question types there would be several response field names per slot.
- responses.{slot no}.{-behaviour var name} You can also specify behaviour vars in the responses such as -tryagain or -submit
 - finished - this column specifies whether the attempt should be finished after the responses have been processed. If the
 column is omitted then the default is to finish each attempt in each row.


