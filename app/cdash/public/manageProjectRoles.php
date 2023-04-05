<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

require_once 'include/pdo.php';
require_once 'include/common.php';
require_once 'include/cdashmail.php';

use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use CDash\Config;
use \CDash\Database;
use CDash\Model\Project;
use CDash\Model\UserProject;
use Illuminate\Support\Facades\Auth;

$config = Config::getInstance();

if (Auth::check()) {
    $usersessionid = Auth::id();
    // Checks
    if (!isset($usersessionid) || !is_numeric($usersessionid)) {
        echo 'Not a valid usersessionid!';
        return;
    }

    $current_user = Auth::user();

    @$projectid = $_GET['projectid'];

    $project = new Project();

    // If the projectid is not set and there is only one project we go directly to the page
    if (!isset($projectid)) {
        $projectids = $project->GetIds();
        if (count($projectids) == 1) {
            $projectid = $projectids[0];
        }
    }
    $projectid = intval($projectid);

    $role = 0;
    if ($projectid && is_numeric($projectid)) {
        $current_user_project = new UserProject();
        $current_user_project->ProjectId = $projectid;
        $current_user_project->UserId = $usersessionid;
        $current_user_project->FillFromUserId();
        $role = $current_user_project->Role;
    }

    if (!$current_user->admin && $role <= 1) {
        echo "You don't have the permissions to access this page";
        return;
    }

    $xml = begin_XML_for_XSLT();
    $xml .= '<backurl>user.php</backurl>';
    $xml .= '<title>CDash - Project Roles</title>';
    $xml .= '<menutitle>CDash</menutitle>';
    $xml .= '<menusubtitle>Project Roles</menusubtitle>';

    // Form post
    @$adduser = $_POST['adduser'];
    @$removeuser = $_POST['removeuser'];

    @$userid = $_POST['userid'];
    if ($userid != null) {
        $userid = pdo_real_escape_numeric($userid);
    }

    @$role = $_POST['role'];
    if ($role != null) {
        $role = pdo_real_escape_numeric($role);
    }

    @$emailtype = $_POST['emailtype'];
    if ($emailtype != null) {
        $emailtype = pdo_real_escape_numeric($emailtype);
    }

    @$credentials = $_POST['credentials'];
    @$repositoryCredential = $_POST['repositoryCredential'];
    @$updateuser = $_POST['updateuser'];
    @$importUsers = $_POST['importUsers'];
    @$registerUsers = $_POST['registerUsers'];

    @$registerUser = $_POST['registerUser'];

    // Register a user and send the email
    if (!function_exists('register_user')) {
        function register_user($projectid, $email, $firstName, $lastName, $repositoryCredential)
        {
            $config = Config::getInstance();

            $UserProject = new UserProject();
            $UserProject->ProjectId = $projectid;

            $user = User::where('email', $email)->first();
            // Check if the user is already registered
            if ($user) {
                $userid = $user->id;
                // Check if the user has been registered to the project
                $UserProject->UserId = $userid;
                if (!$UserProject->Exists()) {
                    // not registered

                    // We register the user to the project
                    $UserProject->Role = 0;
                    $UserProject->EmailType = 1;
                    $UserProject->Save();

                    // We add the credentials if not already added
                    $UserProject->AddCredential($repositoryCredential);
                    $UserProject->ProjectId = 0;
                    $UserProject->AddCredential($email); // Add the email by default

                    echo pdo_error();
                    return false;
                }
                return '<error>User ' . $email . ' already registered.</error>';
            } // already registered

            // Check if the repositoryCredential exists for this project
            $UserProject->RepositoryCredential = $repositoryCredential;
            if ($UserProject->FillFromRepositoryCredential() === true) {
                return '<error>' . $repositoryCredential . ' was already registered for this project under a different email address</error>';
            }

            // Register the user
            // Create a new password
            $pass = generate_password(10);
            $passwordHash = password_hash($pass, PASSWORD_DEFAULT);

            if ($passwordHash === false) {
                $xml .= '<error>Failed to hash password.</error>';
                return false;
            }

            $user = new User();
            $user->password = $passwordHash;
            $user->email = $email;
            $user->firstname = $firstName;
            $user->lastname = $lastName;
            $user->save();
            $userid = $user->id;

            // Insert the user into the project
            $UserProject->UserId = $userid;
            $UserProject->ProjectId = $projectid;
            $UserProject->Role = 0;
            $UserProject->EmailType = 1;
            $UserProject->Save();

            // We add the credentials if not already added
            $UserProject->AddCredential($repositoryCredential);
            $UserProject->ProjectId = 0;
            $UserProject->AddCredential($email); // Add the email by default

            $currentURI = $config->getBaseUrl();

            $prefix = '';
            if (strlen($firstName) > 0) {
                $prefix = ' ';
            }

            $project = new Project();
            $project->Id = $projectid;
            $projectname = $project->GetName();

            // Send the email
            $text = 'Hello' . $prefix . $firstName . ",\n\n";
            $text .= 'You have been registered to CDash because you have access to the source repository for ' . $projectname . "\n";
            $text .= 'To access your CDash account: ' . $currentURI . "/user.php\n";
            $text .= 'Your login is: ' . $email . "\n";
            $text .= 'Your password is: ' . $pass . "\n\n";
            $text .= 'Generated by CDash.';

            if (cdashmail("$email", 'CDash - ' . $projectname . ' : Subscription', "$text")) {
                echo 'Email sent to: ' . $email . '<br>';
            } else {
                add_log("cannot send email to: $email", 'register_user', LOG_ERR);
            }
            return true;
        }
    }

    if (isset($_POST['sendEmailToSiteMaintainers'])) {
        $emailMaintainers = htmlspecialchars(pdo_real_escape_string($_POST['emailMaintainers']));
        if (strlen($emailMaintainers) < 50) {
            $xml .= '<error>The email should be more than 50 characters.</error>';
        } else {
            $maintainerids = find_site_maintainers(intval($projectid));
            $email = '';
            foreach ($maintainerids as $maintainerid) {
                if (strlen($email) > 0) {
                    $email .= ', ';
                }
                $maintainer = new User();
                $maintainer->Id = $maintainerid;
                $email .= $maintainer->GetEmail();
            }

            $projectname = get_project_name($projectid);
            if ($email != '') {
                if (cdashmail("$email", 'CDash - ' . $projectname . ' : To Site Maintainers', "$emailMaintainers")) {
                    $xml .= '<warning>Email sent to site maintainers.</warning>';
                } else {
                    $xml .= '<error>Cannot send email to site maintainers.</error>';
                }
            } else {
                $xml .= '<error>There is no site maintainers for this project.</error>';
            }
        }
    }

    // Register a user
    if ($registerUser) {
        @$email = $_POST['registeruseremail'];
        if ($email != null) {
            $email = htmlspecialchars(pdo_real_escape_string($email));
        }
        @$firstName = $_POST['registeruserfirstname'];
        if ($firstName != null) {
            $firstName = htmlspecialchars(pdo_real_escape_string($firstName));
        }
        @$lastName = $_POST['registeruserlastname'];
        if ($lastName != null) {
            $lastName = htmlspecialchars(pdo_real_escape_string($lastName));
        }
        @$repositoryCredential = $_POST['registeruserrepositorycredential'];

        if (strlen($email) < 3 || strlen($firstName) < 2 || strlen($lastName) < 2) {
            $xml .= '<error>Email, first name and last name should be filled out.</error>';
        } else {
            // Call the register_user function
            $xml .= register_user($projectid, $email, $firstName, $lastName, $repositoryCredential);
        }
    }

    // Register CVS users
    if ($registerUsers) {
        $cvslogins = $_POST['cvslogin'];
        $emails = $_POST['email'];
        $firstnames = $_POST['firstname'];
        $lastnames = $_POST['lastname'];
        $cvsuser = $_POST['cvsuser'];

        for ($logini = 0; $logini < count($cvslogins); $logini++) {
            if (!isset($cvsuser[$logini])) {
                continue;
            }

            $cvslogin = $cvslogins[$logini];
            $email = $emails[$logini];
            $firstName = $firstnames[$logini];
            $lastName = $lastnames[$logini];

            // Call the register_user function
            $xml .= register_user($projectid, $email, $firstName, $lastName, $cvslogin);
        }
    }

    // Add a user
    if ($adduser) {
        $UserProject = new UserProject();
        $UserProject->ProjectId = $projectid;
        $UserProject->UserId = $userid;
        if (!$UserProject->Exists()) {
            $UserProject->Role = $role;
            $UserProject->EmailType = 1;
            $UserProject->Save();
            $UserProject->AddCredential($repositoryCredential);
        }
    }

    $db = Database::getInstance();

    // Remove the user
    if ($removeuser) {
        $db->executePrepared('DELETE FROM user2project WHERE userid=? AND projectid=?', [$userid. $projectid]);
        $db->executePrepared('DELETE FROM user2repository WHERE userid=? AND projectid=?', [$userid. $projectid]);
        echo pdo_error();
    }

    // Update the user
    if ($updateuser) {
        // Update the credentials
        $UserProject = new UserProject();
        $UserProject->ProjectId = $projectid;
        $UserProject->UserId = $userid;

        $credentials_array = explode(';', $credentials);
        $UserProject->UpdateCredentials($credentials_array);

        $UserProject->Role = $role;
        $UserProject->EmailType = $emailtype;
        $UserProject->Save();
    }

    // Import the users from CVS
    if ($importUsers) {
        $contents = file_get_contents($_FILES['cvsUserFile']['tmp_name']);
        if (strlen($contents) > 0) {
            $id = 0;
            $pos = 0;
            $pos2 = strpos($contents, "\n");
            while ($pos !== false) {
                $line = substr($contents, $pos, $pos2 - $pos);

                $email = '';
                $svnlogin = '';
                $firstname = '';
                $lastname = '';

                // first is the svnuser
                $possvn = strpos($line, ':');
                if ($possvn !== false) {
                    $svnlogin = trim(substr($line, 0, $possvn));

                    $posemail = strpos($line, ':', $possvn + 1);
                    if ($posemail !== false) {
                        $email = trim(substr($line, $possvn + 1, $posemail - $possvn - 1));

                        $name = substr($line, $posemail + 1);
                        $posname = strpos($name, ',');
                        if ($posname !== false) {
                            $name = substr($name, 0, $posname);
                        }

                        $name = trim($name);

                        // Find the firstname
                        $posfirstname = strrpos($name, ' ');
                        if ($posfirstname !== false) {
                            $firstname = trim(substr($name, 0, $posfirstname));
                            $lastname = trim(substr($name, $posfirstname));
                        } else {
                            $firstname = $name;
                        }
                    } else {
                        $email = trim(substr($line, $possvn + 1));
                    }
                }

                if (strlen($email) > 0 && $email != 'kitware@kitware.com') {
                    $xml .= '<cvsuser>';
                    $xml .= '<email>' . $email . '</email>';
                    $xml .= '<cvslogin>' . $svnlogin . '</cvslogin>';
                    $xml .= '<firstname>' . $firstname . '</firstname>';
                    $xml .= '<lastname>' . $lastname . '</lastname>';
                    $xml .= '<id>' . $id . '</id>';
                    $xml .= '</cvsuser>';
                    $id++;
                }

                $pos = $pos2;
                $pos2 = strpos($contents, "\n", $pos2 + 1);
            }
        } else {
            echo 'Cannot parse CVS users file';
        }
    }

    $sql = 'SELECT id, name FROM project';
    $params = [];
    if (!$current_user->admin) {
        $sql .= ' WHERE id IN (SELECT projectid AS id FROM user2project WHERE userid=? AND role>0)';
        $params[] = intval($usersessionid);
    }
    $sql .= ' ORDER BY name';
    $projects = $db->executePrepared($sql, $params);
    foreach ($projects as $project_array) {
        $xml .= '<availableproject>';
        $xml .= add_XML_value('id', $project_array['id']);
        $xml .= add_XML_value('name', $project_array['name']);
        if ($project_array['id'] == $projectid) {
            $xml .= add_XML_value('selected', '1');
        }
        $xml .= '</availableproject>';
    }

    // If we have a project id
    if ($projectid > 0) {
        $project = new Project();
        $project->Id = $projectid;
        $projectname = $project->GetName();
        $xml .= '<project>';
        $xml .= add_XML_value('id', $projectid);
        $xml .= add_XML_value('name', $projectname);
        $xml .= add_XML_value('name_encoded', urlencode($projectname));
        $xml .= '</project>';

        // List the users for that project
        $user = $db->executePrepared('
                    SELECT
                        u.id,
                        u.firstname,
                        u.lastname,
                        u.email,
                        up.role,
                        up.emailtype
                    FROM
                        user2project AS up,
                        ' . qid('user') . ' AS u
                    WHERE
                        u.id=up.userid
                        AND up.projectid=?
                    ORDER BY u.firstname ASC
                ', [intval($projectid)]);
        add_last_sql_error('ManageProjectRole');

        $i = 0;
        foreach ($user as $user_array) {
            $userid = intval($user_array['id']);
            $xml .= '<user>';

            if ($i % 2 === 0) {
                $xml .= add_XML_value('bgcolor', '#CADBD9');
            } else {
                $xml .= add_XML_value('bgcolor', '#FFFFFF');
            }
            $i++;
            $xml .= add_XML_value('id', $userid);
            $xml .= add_XML_value('firstname', $user_array['firstname']);
            $xml .= add_XML_value('lastname', $user_array['lastname']);
            $xml .= add_XML_value('email', $user_array['email']);

            $credentials = $db->executePrepared('
                               SELECT credential
                               FROM user2repository as ur
                               WHERE
                                   ur.userid=?
                                   AND (
                                       ur.projectid=?
                                       OR ur.projectid=0
                                   )
                           ', [$userid, intval($projectid)]);
            add_last_sql_error('ManageProjectRole');

            foreach ($credentials as $credentials_array) {
                $xml .= add_XML_value('repositorycredential', $credentials_array['credential']);
            }

            $xml .= add_XML_value('role', $user_array['role']);
            $xml .= add_XML_value('emailtype', $user_array['emailtype']);

            $xml .= '</user>';
        }

        if (is_array($project_array)) {
            // Check if a user is committing without being registered to CDash or with email disabled
            $date = date(FMT_DATETIME, strtotime(date(FMT_DATETIME) . ' -30 days'));
            $query = $db->executePrepared('
                         SELECT DISTINCT
                             author,
                             emailtype,
                             u.email
                         FROM
                             dailyupdate,
                             dailyupdatefile
                         LEFT JOIN user2repository ON (
                             dailyupdatefile.author=user2repository.credential
                             AND (
                                 user2repository.projectid=0
                                 OR user2repository.projectid=?
                             )
                         )
                         LEFT JOIN user2project ON (
                             user2repository.userid= user2project.userid
                             AND user2project.projectid?
                         )
                         LEFT JOIN ' . qid('user') . ' AS u ON (
                             user2project.userid=u.id
                         )
                         WHERE
                             dailyupdatefile.dailyupdateid=dailyupdate.id
                             AND dailyupdate.projectid=?
                             AND dailyupdatefile.checkindate>?
                             AND (
                                 emailtype=0
                                 OR emailtype IS NULL
                             )
                     ', [
                         intval($project_array['id']),
                         intval($project_array['id']),
                         intval($project_array['id']),
                         $date
                     ]);

            add_last_sql_error('ManageProjectRole');
            foreach ($query as $query_array) {
                $xml .= '<baduser>';
                $xml .= add_XML_value('author', $query_array['author']);
                $xml .= add_XML_value('emailtype', $query_array['emailtype']);
                $xml .= add_XML_value('email', $query_array['email']);
                $xml .= '</baduser>';
            }
        }
    }

    if ($config->get('CDASH_FULL_EMAIL_WHEN_ADDING_USER') == 1) {
        $xml .= add_XML_value('fullemail', '1');
    }
    $xml .= '</cdash>';

    // Now doing the xslt transition
    generate_XSLT($xml, 'manageProjectRoles');
} else {
    return LoginController::staticShowLoginForm();
}
