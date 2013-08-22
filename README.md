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

See the example quiz back-up and stepdata.csv file in the mod/quiz/report/simulate/example/ directory.


