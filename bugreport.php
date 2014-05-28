<?php
include "functions.php";
?>


<?php



$remote_ip= trim($_SERVER['REMOTE_ADDR']);

error_log("Connecting from ".$remote_ip."\n", 3, "debug.log");
// check black list of IP address
$isIPInBlackList = isIPAddressInBlackList();

if ($isIPInBlackList){
    error_log("address in black list: ".$isIPInBlackList."\n", 3, "debug.log");
    return;
}

// mode = 'new', Data is submited by user
// mode = 'edit', Cytostaff edit the data in bugs DB
$mode = 'new'; // by default it is 'new'

// If bugid is provided through URL, it is in edit mode
$bugid = getBugID();

$pageTitle = getPageTitle($mode);

showPageHeader($pageTitle);
?>

<div class="blockfull">

<?php

$tried = NULL;
if (isset ($_POST['tried'])) {
    $tried = 'yes';
}

$connection = getDBConnection($mode);

// initialize the variables
$bugReport = NULL;

// Remember the form data. If form validation failed, these data will
// be used to fill the refreshed form. If pass, they will be saved into
// database

//include "formUserInput_remember.inc";
error_log("fetching form\n", 3, "debug.log");
$bugReport = getBugReportFromForm();
if( array_key_exists('cysubject', $bugReport) )
{
    error_log("subject: ".$bugReport['cysubject']."\n", 3, "debug.log");
    error_log(date(DATE_RFC2822)." bugReport: ".toJSON($bugReport)."\n", 3, "debug.log");
}


//////////////////////// Form validation ////////////////////////
#Error variables
$nameErr = $emailErr = $versionErr = $osErr = $subjectErr = $descriptionErr ="";
if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    $validated = isUserInputValid($bugReport);
}
else
{
    $validated = true;
}

/////////////////////// Form definition ////////////////////////
if (!(($tried != NULL && $tried == 'yes') && $validated)) {
    ?>	<div class="BugReport">
        <h1>Cytoscape Bug Report Form</h1>
        <h2>Please Read before Submitting New Bug</h2>
        <p>
            Please use this form to report <strong>reproducible</strong> Cytoscape bugs.
            It is helpful if you can confirm that the bug is reproducible on another computer.
            Please use the Cytoscape <a href="https://groups.google.com/forum/?hl=en_US/#!forum/cytoscape-helpdesk">helpdesk</a>
            instead to ask general questions about Cytoscape, including questions about
            Cytoscape installation problems and installing apps.
        </p>
        <?php
        showForm($bugReport);
        ?>
    </div>
<?php
}
else {
    ////////////////////////// form processing /////////////////////////
    // if mode = 'new', takes the details of the bug from user with status = 'new'.
    // if mode = 'Edit', update the bug info in bugs DB

    // In case of edit, do updating
    if ($mode == 'new') {
        //process the data and Save the data into DB.
        //$submitResult1 = submitNewBug($connection, $bugReport);
        //submitNewBug2Remine( $bugReport, $submitResult1);

        $attachedFileURL = loadAttachment2DB($connection, $bugReport);

        submitNewBug2Remine( $bugReport, $attachedFileURL);
        $bug_id_redmine = getBugID_redmine();
        addReporter2BugWatch($bug_id_redmine, $bugReport['email']);
        sendNotificationEmail($bugReport, $bug_id_redmine);
    }
    else {
        error_log("mode: ".$mode."\n", 3, "debug.log");
    }
}
?>

<?php
showPageTail();
///////////////////// End of page ////////////////////////////////////
?>


<?php

function loadAttachment2DB($connection, $bugReport){
    $attachedFileURL = null;

    // load attached file
    $file_auto_id = null;

    if ($bugReport['attachedFiles'] != NULL && $bugReport['attachedFiles']['name'] != NULL){

        $name = mysql_real_escape_string($bugReport['attachedFiles']['name']);
        $type = $bugReport['attachedFiles']['type'];
        $md5 = $bugReport['attachedFiles']['md5'];
        $content = $bugReport['attachedFiles']['fileContent'];

        $dbQuery = "INSERT INTO attached_files VALUES ";
        $dbQuery .= "(0, '$name', '$type', '$content', '$md5')";
        // Run the query
        if (!(@ mysql_query($dbQuery, $connection)))
            showerror();

        $file_auto_id = mysql_insert_id($connection);
    }

    if ($file_auto_id != null){
        // There are attachment in this bug report
        $attachedFileURL = "http://chianti.ucsd.edu/cyto_web/bugreport/attachedFiledownload.php?file_id=".$file_auto_id;
    }

    return $attachedFileURL;
}

function addReporter2BugWatch($id, $reporterEmail){

    //$url = "http://code.cytoscape.org/redmine/issues/".$bug_id_redmine."/add_cc?email=".$reporterEmail."&key=e0ea356348ee0786edd64a56bb3a87eb8a07b082";
    $url = "http://code.cytoscape.org/redmine/issues/".$id."//cc_addresses/create/".$id."/?key=e0ea356348ee0786edd64a56bb3a87eb8a07b082&cc_address[mail]=".$reporterEmail."&cc_address[issue_id]=".$id;


    $ch = curl_init($url);
    $fp = fopen("_addReporter2bugWatch.txt", "w");

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
}

function isIPAddressInBlackList(){

    $remote_ip_address= trim($_SERVER['REMOTE_ADDR']);

    $file_handle = fopen("_blacklist_ip.txt", "r");
    while (!feof($file_handle)) {
        $line = trim(fgets($file_handle));
        if(strcasecmp($remote_ip_address, $line) == 0){
            return true;
        }
    }
    fclose($file_handle);
    return false;
}


function getBugID_redmine() {
    $str = file_get_contents("_reportOutput.txt");
    $obj = json_decode($str);
    return $obj->{'issue'}->{'id'};
}


function submitNewBug2Remine( $bugReport, $submitResult) {
    error_log("Starting to submit bug\n", 3, "debug.log");
    // Save the bug report in a tmp file 'newBug.jason' in jason format
    $myFile = "_newBug.json";
    $fh = fopen($myFile, 'w') or die("can't open file _newBug.json");

    $json = toJSON($bugReport, $submitResult);

    error_log("Submitting bug ".$json."\n", 3, "debug.log");
    fwrite($fh, $json);

    fclose($fh);

    // submit the new bug to redmine (Cytosape bug tracker)
    system("./run_curl.sh > _reportOutput.txt");
}

function toJSON( $bugReport, $submitResult) {
    $description = "";
    if( array_key_exists('cyversion', $bugReport) && array_key_exists('description', $bugReport) )
    {
        $description = '\\nOS: '.$bugReport['os'].'\nCytoscape version: '.$bugReport['cyversion'].'\\n\\n'.$bugReport['description'];
    }
    if ($submitResult != null){
        $description = $description."\\n\\n\\nAttached file is at ".$submitResult."\\n\\n\\n";
    }

    $description = $description.'\\n\\n\\nReported by: '.$bugReport['name']; //.'\nE-mail: '.$bugReport['email'];
    $description = $description.'\\nEmail: '.$bugReport['email'];

    $subject = $bugReport['cysubject'];
    if ($subject != null) {
        $subject = trim($subject);
    }
    if ($subject == null || $subject == ""){
        $subject = "no subject";
    }
    $json =
        "{
                \"issue\": {
                \"project_id\": \"cytoscape3\",
                \"subject\": \"".clean_unwanted_characters($subject)."\",
				\"description\": \"".clean_unwanted_characters($description)."\"
				}
		}";
    return $json;
}


// To prevent JSON injection attack
function clean_unwanted_characters($oneStr){
    $cleaned_str = $oneStr;
    return $cleaned_str;

}


function isUserInputValid($userInput) {
    if ($userInput == NULL){
        return false;
    }

    $errorFound = false;
    //Required Fields
    //name
    //email
    //cyversion
    //cysubject
    //os
    //description
    if( !array_key_exists('name', $userInput) || $userInput['name'] == null )
    {
        global $nameErr;
        $nameErr = "* Name is a required field.";
        $errorFound = true;
    }

    if( !array_key_exists('email', $userInput) || $userInput['email'] == null )
    {
        global $emailErr;
        $emailErr = "* Email is a required field.";
        $errorFound = true;
    }

    if( !array_key_exists('cyversion', $userInput) || $userInput['cyversion'] == null )
    {
        global $versionErr;
        $versionErr = "* Version is a required field and must start with 3.x -- (where x is any valid version number)";
        $errorFound = true;
    }
    else if( !(strpos($userInput['cyversion'], "3.") === 0) )
    {
        global $versionErr;
        $versionErr = "* Version must start with 3.x -- (where x is any valid version number)";
        $errorFound = true;
    }

    if( !array_key_exists('cysubject', $userInput) || $userInput['cysubject'] == null )
    {
        global $subjectErr;
        $subjectErr = "* Subject is a required field.";
        $errorFound = true;
    }

    if( !array_key_exists('os', $userInput) || $userInput['os'] == null )
    {
        global $osErr;
        $osErr = "* OS is a required field.";
        $errorFound = true;
    }

    if( !array_key_exists('description', $userInput) || $userInput['description'] == null )
    {
        global $descriptionErr;
        $descriptionErr = "* Description is required.";
        $errorFound = true;
    }


   return !$errorFound;
}

function sendNotificationEmail($bugReport, $bug_id_redmine) {

    include 'cytostaff_emails.inc';

    $from = $cytostaff_emails[0];
    $to = "";

    for ($i=1; $i<count($cytostaff_emails); $i++){
        $to = $to . $cytostaff_emails[$i] . " ";
    }

    $subject = "[cytoweb-bug] New bug submitted by ".$bugReport['name'];

    $prefix  = "\n\n******* Do NOT reply to this email. This is notification only e-mail to cytostaff. ******\n".
        " Here is the contact info of the reporter: name: ".$bugReport['name'].", e-mail: ".$bugReport['email']."\n\n";

    $body = $prefix.stripslashes($bugReport['description'])."\n\nBug URL: http://code.cytoscape.org/redmine/issues/".$bug_id_redmine;

    ?>
    Your bug report has been submitted and Cytoscape staff will review your report.
    Thank you for helping to make Cytoscape better!
    <?php

    $headers = "From: " . $from . "\r\n";

    // Send e-mail to staff now
    if (mail($to, $subject, $body, $headers)) {
        //echo("<p>New bug report e-mail was sent to Cytostaff!</p>");
    } else {
        echo("<p>Failed to send a notification e-mail to cytostaff...</p>");
    }
}


function showForm($userInput) {
    global $nameErr, $emailErr, $versionErr, $osErr, $subjectErr, $descriptionErr;
    ?>
    <p style="color:red;">All fields are required, attachment is optional.</p>
    <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="post" enctype="multipart/form-data" name="submitbug" id="form1">
        <label for="tfName">Name</label>
        <input name="tfName" type="text" id="tfName" value="<?php if (isset($userInput['name'])) echo $userInput['name']; ?>" />
        <span style="color:red"><?php echo $nameErr;?></span>
        <div>

            <div>
                <label for="tfEmail">Email</label>
                <input name="tfEmail" type="text" id="tfEmail" value="<?php if (isset($userInput['email'])) echo $userInput['email']; ?>" />
                <span style="color:red"><?php echo $emailErr;?></span>
            </div>

            <div>
                <label for="cyversion">Cytoscape version</label>
                <input name="tfCyversion" type="text" id="tfCyversion" value="<?php if (isset($userInput['cyversion'])) echo $userInput['cyversion']; ?>" />
                <span style="color:red"><?php echo $versionErr;?></span>
            </div>

            <div>
                <label for="os">Operating system</label>
                <input name="tfOS" type="text" id="tfOS" value="<?php if (isset($userInput['os'])) echo $userInput['os']; ?>" />
                <span style="color:red"><?php echo $osErr;?></span>
            </div>

            <div>
                <label for="cysubject">Subject</label>
                <input name="tfSubject" type="text" id="cysubject" value="<?php if (isset($userInput['cysubject'])) echo $userInput['cysubject']; ?>" />
                <span style="color:red"><?php echo $subjectErr;?></span>
            </div>


            <br/>
            <div>
                <label for="taDescription">Problem description</label>
                <span style="color:red"><?php echo $descriptionErr;?></span>
            </div>
            <div>
                <textarea name="taDescription" id="taDescription"><?php if (isset($userInput['description'])) echo $userInput['description']; ?></textarea>
            </div>

            <div>

                Optional, Attachments (Session files, data files, screen-shots, etc.)
            </div>
            <input type="file" name="attachments" id="attachments" />

        </div>

        <!-- 
        <div>
        <input name="ufile[]" type="file" id="ufile[]" size="50" />
        </div>
         -->

        <div>
            <input name="tried" type="hidden" value="yes" />
        </div>
        <div>
            <input type="submit" name="btnBubmit" id="btnSubmit" value="Submit" />
        </div>
    </form>


<?php
}


function getBugReportFromForm(){

    $bugReport = NULL;

    if (isset ($_POST['tfName'])) {
        $bugReport['name'] = clean_input($_POST['tfName']);
    }

    if (isset ($_POST['tfEmail'])) {
        $bugReport['email'] = clean_input(addslashes($_POST['tfEmail']));
    }

    // Get cyversion from URL
    if (isset ($_GET['cyversion'])) {
        $bugReport['cyversion'] = clean_input(addslashes($_GET['cyversion']));
    }

    if (isset ($_POST['tfCyversion'])) {
        $bugReport['cyversion'] = clean_input(addslashes($_POST['tfCyversion']));
    }

    if (isset ($_POST['tfSubject'])) {
        $bugReport['cysubject'] = clean_input(addslashes($_POST['tfSubject']));
    }

    $bugReport['os'] = clean_input(getOSFromUserAgent());

    if (isset ($_GET['os'])) {
        $bugReport['os'] = clean_input(addslashes($_GET['os']));
    }

    if (isset ($_POST['os'])) {
        $bugReport['os'] = clean_input(addslashes($_POST['os']));
    }

    if (isset ($_POST['taDescription'])) {
        $bugReport['description'] = clean_input(addslashes($_POST['taDescription']));
    }

    if (isset ($_FILES['attachments'])) {
        if ($_FILES['attachments']['name']!= NULL){ // a file is selected
            $bugReport['attachedFiles']['name'] = $_FILES['attachments']['name'];
            $bugReport['attachedFiles']['type'] = $_FILES['attachments']['type'];

            $bugReport['attachedFiles']['md5'] = md5_file($_FILES['attachments']['tmp_name']);;

            // Get file content
            $fileHandle = fopen($_FILES['attachments']['tmp_name'], "r");
            $fileContent = fread($fileHandle, $_FILES['attachments']['size']);
            $fileContent = addslashes($fileContent);

            $bugReport['attachedFiles']['fileContent'] = $fileContent;
        }
    }

    if (isset ($_SERVER['REMOTE_ADDR'])) {
        $bugReport['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $bugReport['remote_host'] = gethostbyaddr($bugReport['ip_address']);
    }

    return $bugReport;
}

#Purpose: Security to avoid cross-site scripting attacks.
function clean_input($data) {
    $data = trim($data);
    $data = htmlspecialchars($data);
    return $data;
}


function getOSFromUserAgent(){

    $os = "unknown";

    $userAgent = $_SERVER["HTTP_USER_AGENT"];

    if (strpos($userAgent, 'Linux') ? true : false){
        $os = "Linux";
    }
    else if (strpos($userAgent, 'Macintosh') ? true : false){
        $os = "Mac";
    }
    else if (strpos($userAgent, 'Windows') ? true : false){
        $os = "Windows";
    }

    return $os;
}


function getBugID(){
    $bugid = NULL; // for edit mode only

    if (isset ($_GET['bugid'])) {
        $bugid = ($_GET['bugid']);
    }
    if (isset ($_POST['bugid'])) { // hidden field
        $bugid = ($_POST['bugid']);
    }
    return $bugid;
}


function getPageTitle($mode) {
    // Set the page title based on the mode
    if ($mode == 'new') {
        $pageTitle = 'Submit bug to Cytoscape';
    }
    else
    {
        if ($mode == 'edit') {
            $pageTitle = 'Edit bug in bugs DB';
        } else {
            exit ('Unknown page mode, mode must be either new or edit');
        }
    }
    return $pageTitle;
}

?>


