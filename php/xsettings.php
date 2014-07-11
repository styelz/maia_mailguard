<?php
    /*
     * $Id$
     *
     * MAIA MAILGUARD LICENSE v.1.0
     *
     * Copyright 2009 by Robert LeBlanc <rjl@renaissoft.com>
     *                   David Morton   <mortonda@dgrmm.net>
     * All rights reserved.
     *
     * PREAMBLE
     *
     * This License is designed for users of Maia Mailguard
     * ("the Software") who wish to support the Maia Mailguard project by
     * leaving "Maia Mailguard" branding information in the HTML output
     * of the pages generated by the Software, and providing links back
     * to the Maia Mailguard home page.  Users who wish to remove this
     * branding information should contact the copyright owner to obtain
     * a Rebranding License.
     *
     * DEFINITION OF TERMS
     *
     * The "Software" refers to Maia Mailguard, including all of the
     * associated PHP, Perl, and SQL scripts, documentation files, graphic
     * icons and logo images.
     *
     * GRANT OF LICENSE
     *
     * Redistribution and use in source and binary forms, with or without
     * modification, are permitted provided that the following conditions
     * are met:
     *
     * 1. Redistributions of source code must retain the above copyright
     *    notice, this list of conditions and the following disclaimer.
     *
     * 2. Redistributions in binary form must reproduce the above copyright
     *    notice, this list of conditions and the following disclaimer in the
     *    documentation and/or other materials provided with the distribution.
     *
     * 3. The end-user documentation included with the redistribution, if
     *    any, must include the following acknowledgment:
     *
     *    "This product includes software developed by Robert LeBlanc
     *    <rjl@renaissoft.com>."
     *
     *    Alternately, this acknowledgment may appear in the software itself,
     *    if and wherever such third-party acknowledgments normally appear.
     *
     * 4. At least one of the following branding conventions must be used:
     *
     *    a. The Maia Mailguard logo appears in the page-top banner of
     *       all HTML output pages in an unmodified form, and links
     *       directly to the Maia Mailguard home page; or
     *
     *    b. The "Powered by Maia Mailguard" graphic appears in the HTML
     *       output of all gateway pages that lead to this software,
     *       linking directly to the Maia Mailguard home page; or
     *
     *    c. A separate Rebranding License is obtained from the copyright
     *       owner, exempting the Licensee from 4(a) and 4(b), subject to
     *       the additional conditions laid out in that license document.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDER AND CONTRIBUTORS
     * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
     * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
     * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
     * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
     * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
     * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
     * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
     * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
     * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
     * USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     *
     */

    require_once ("core.php");
    require_once ("authcheck.php");
    require_once ("auth.php");
    require_once ("display.php");
    require_once ("maia_db.php");
    $display_language = get_display_language($euid);
    require_once ("./locale/$display_language/display.php");
    require_once ("./locale/$display_language/db.php");
    require_once ("./locale/$display_language/settings.php");
    require_once ("./locale/$display_language/domainsettings.php");



    //First set up some common variables and verify that they make sense

    //Some basic system config stuff....
    $select = "SELECT enable_charts, reminder_threshold_count, " .
                        "enable_spamtraps, enable_username_changes, " .
                        "enable_address_linking " .
                 "FROM maia_config WHERE id = 0";
    $sth = $dbh->query($select);
    if ($row = $sth->fetchrow()) {
           $enable_charts = ($row["enable_charts"] == 'Y');
           $reminder_threshold_count = $row["reminder_threshold_count"];
           $enable_spamtraps = ($row["enable_spamtraps"] == 'Y');
           $enable_username_changes = ($row["enable_username_changes"] == 'Y');
           $enable_address_linking = ($row["enable_address_linking"] == 'Y');
    }
    $sth->free();
    $super = is_superadmin($uid);

    // set up domain variables if the current focus is a domain user
    if (is_a_domain_default_user($euid)) {
        $domain_user = true;
        $domain_name = get_user_name($euid);
        $domain_id = get_domain_id($domain_name);
        
    } else {
         $domain_user = false;
         $domain_name = "";
         $domain_id ="";
    }

    // make sure the posted data fits the session data
    if (isset($_POST['domain_id']) && $_POST['domain_id'] != $domain_id) {
        $logger->err("xsettings.php: domain_id doesn't match.  Expected $domain_id but got {$_POST['domain_id']}");
        header("Location: index.php$msid");
        exit;
    }


    /* The settings page can post various actions and forms, which we determine here by the name of the submit button used.
     *
     */

    /*  Pressed the "Update This Address' Settings" button or the
     *  "Update ALL Addresses' Settings" button  
     *
     */
    if (isset($_POST["upone"]) ||
        isset($_POST["upall"])) {
            
        if (isset($_POST['address_id'])) {
            $address_id = $_POST['address_id'];
        } else {
            $logger->err("xsettings.php: address_id not found.");
            header("Location: index.php$msid");
            exit;
        }

        $sth = $dbh->prepare("SELECT policy_id, email, maia_user_id FROM users
                   WHERE users.maia_user_id = ? AND users.id = ?");
        $res = $sth->execute(array($euid, $address_id));
        if (PEAR::isError($sth)) {
            die($sth->getMessage());
        }
        if ($res->numRows() == 0 ) {
            $logger->err("xsettings.php: address_id doesn't belong to effective user: $address_id");
            header("Location: logout.php");
            exit;
        }

        $row = $res->fetchRow();

        if (! (is_admin_for_domain($uid, 
                                   get_domain_id("@" . get_domain_from_email($row["email"])))
            || $super
            || $row["maia_user_id"] == $euid
            )) {
                $logger->err("xsettings.php: failed security check.");
                header("Location: logout.php");
                exit;
        }

        $policy_id = $row['policy_id'];

        $sth->free();
        $sth = $dbh->prepare("SELECT virus_lover, " .
                        "spam_lover, " .
                        "banned_files_lover, " .
                        "bad_header_lover, " .
                        "bypass_virus_checks, " .
                        "bypass_spam_checks, " .
                        "bypass_banned_checks, " .
                        "bypass_header_checks, " .
                        "discard_viruses, " .
                        "discard_spam, " .
                        "discard_banned_files, " .
                        "discard_bad_headers, " .
                        "spam_modifies_subj, " .
                        "spam_tag_level, " .
                        "spam_tag2_level, " .
                        "spam_kill_level " .
                 "FROM policy WHERE id = ?");
        $res = $sth->execute(array($policy_id));
        if (PEAR::isError($sth)) {
            die($sth->getMessage());
        }
        if ($row = $res->fetchRow()) {
            $default_quarantine_viruses = ($row["virus_lover"] == "N");
            $default_quarantine_spam = ($row["spam_lover"] == "N");
            $default_quarantine_banned_files = ($row["banned_files_lover"] == "N");
            $default_quarantine_bad_headers = ($row["bad_header_lover"] == "N");
            $default_virus_scanning = ($row["bypass_virus_checks"] == "N");
            $default_spam_filtering = ($row["bypass_spam_checks"] == "N");
            $default_banned_files_checking = ($row["bypass_banned_checks"] == "N");
            $default_bad_header_checking = ($row["bypass_header_checks"] == "N");
            $default_discard_viruses = ($row["discard_viruses"] == "Y");
            $default_discard_spam = ($row["discard_spam"] == "Y");
            $default_discard_banned_files = ($row["discard_banned_files"] == "Y");
            $default_discard_bad_headers = ($row["discard_bad_headers"] == "Y");
            $default_modify_subject = ($row["spam_modifies_subj"] == "Y");
            $default_score_level_1 = $row["spam_tag_level"];
            $default_score_level_2 = $row["spam_tag2_level"];
            $default_score_level_3 = $row["spam_kill_level"];
        }
        $sth->free();

        if (isset($_POST["viruses"])) {
            $viruses = trim($_POST["viruses"]);
        } else {
            $viruses = ($default_virus_scanning ? "yes" : "no");
        }
        if (isset($_POST["virus_destiny"])) {
            $virus_destiny = trim($_POST["virus_destiny"]);
        } else {
            $virus_destiny = ($default_quarantine_viruses ? ($default_discard_viruses ? "discard" : "quarantine") : "label");
        }
        if (isset($_POST["spam"])) {
            $spam = trim($_POST["spam"]);
        } else {
            $spam = ($default_spam_filtering ? "yes" : "no");
        }
        if (isset($_POST["spam_destiny"])) {
            $spam_destiny = trim($_POST["spam_destiny"]);
        } else {
            $spam_destiny = ($default_quarantine_spam ? ($default_discard_spam ? "discard" : "quarantine") : "label");
        }
        if (isset($_POST["banned"])) {
            $banned = trim($_POST["banned"]);
        } else {
            $banned = ($default_banned_files_checking ? "yes" : "no");
        }
        if (isset($_POST["banned_destiny"])) {
            $banned_destiny = trim($_POST["banned_destiny"]);
        } else {
            $banned_destiny = ($default_quarantine_banned_files ? ($default_discard_banned_files ? "discard" : "quarantine") : "label");
        }
        if (isset($_POST["headers"])) {
            $headers = trim($_POST["headers"]);
        } else {
            $headers = ($default_bad_header_checking ? "yes" : "no");
        }
        if (isset($_POST["headers_destiny"])) {
            $headers_destiny = trim($_POST["headers_destiny"]);
        } else {
            $headers_destiny = ($default_quarantine_bad_headers ? ($default_discard_bad_headers ? "discard" : "quarantine") : "label");
        }
        if (isset($_POST["modify_subject"])) {
            $modify_subject = trim($_POST["modify_subject"]);
        } else {
            $modify_subject = ($default_modify_subject ? "yes" : "no");
        }
        if (isset($_POST["level1"])) {
            $level1 = trim($_POST["level1"]);
        } else {
            $level1 = $default_score_level_1;
        }
        if (isset($_POST["level2"])) {
            $level2 = trim($_POST["level2"]);
        } else {
            $level2 = $default_score_level_2;
        }
        if (isset($_POST["level3"])) {
            $level3 = trim($_POST["level3"]);
        } else {
            $level3 = $default_score_level_3;
        }

        $bypass_virus_checks = ($viruses == "yes" ? "N" : "Y");
        $virus_lover = ($virus_destiny == "label" ? "Y" : "N");
        $discard_viruses = ($virus_destiny == "discard" ? "Y" : "N");
        $bypass_spam_checks = ($spam == "yes" ? "N" : "Y");
        $spam_lover = ($spam_destiny == "label" ? "Y" : "N");
        $discard_spam = ($spam_destiny == "discard" ? "Y" : "N");
        $bypass_banned_checks = ($banned == "yes" ? "N" : "Y");
        $banned_files_lover = ($banned_destiny == "label" ? "Y" : "N");
        $discard_banned_files = ($banned_destiny == "discard" ? "Y" : "N");
        $bypass_header_checks = ($headers == "yes" ? "N" : "Y");
        $bad_header_lover = ($headers_destiny == "label" ? "Y" : "N");
        $discard_bad_headers = ($headers_destiny == "discard" ? "Y" : "N");
        $spam_modifies_subj = ($modify_subject == "yes" ? "Y" : "N");
        $spam_tag_level = (double) $level1;
        $spam_tag2_level = (double) $level2;
        $spam_kill_level = (double) $level3;

        // Enforce tag_level <= tag2_level <= kill_level
        if ($spam_tag2_level > $spam_kill_level) {
            $spam_tag2_level = $spam_kill_level;
        }
        if ($spam_tag_level > $spam_tag2_level) {
            $spam_tag_level = $spam_tag2_level;
        }

        // Enforce spam kill level according to spam destiny
        if ($spam_destiny == "label") {
            $spam_kill_level = 999;
        } else {
            $spam_kill_level = $spam_tag2_level;
        }

        // Update the policy table with the new settings.
        $sth = $dbh->prepare("UPDATE policy SET virus_lover = ?, " .
                                    "spam_lover = ?, " .
                                    "banned_files_lover = ?, " .
                                    "bad_header_lover = ?, " .
                                    "bypass_virus_checks = ?, " .
                                    "bypass_spam_checks = ?, " .
                                    "bypass_banned_checks = ?, " .
                                    "bypass_header_checks = ?, " .
                                    "discard_viruses = ?, " .
                                    "discard_spam = ?, " .
                                    "discard_banned_files = ?, " .
                                    "discard_bad_headers = ?, " .
                                    "spam_modifies_subj = ?, " .
                                    "spam_tag_level = ?, " .
                                    "spam_tag2_level = ?, " .
                                    "spam_kill_level = ? " .
                  "WHERE id = ?");

        if (isset($_POST["upall"])) {
            $sth2 = $dbh->prepare("SELECT policy_id FROM users WHERE maia_user_id = ? ");
            $res2 = $sth2->execute(array($euid));
            while ($row = $res2->fetchrow()) {
                $sth->execute(array($virus_lover,
                                           $spam_lover,
                                           $banned_files_lover,
                                           $bad_header_lover,
                                           $bypass_virus_checks,
                                           $bypass_spam_checks,
                                           $bypass_banned_checks,
                                           $bypass_header_checks,
                                           $discard_viruses,
                                           $discard_spam,
                                           $discard_banned_files,
                                           $discard_bad_headers,
                                           $spam_modifies_subj,
                                           $spam_tag_level,
                                           $spam_tag2_level,
                                           $spam_kill_level,
                                           $row["policy_id"]));
                if (PEAR::isError($sth)) {
                    die($sth->getMessage());
                }
            }
            $sth2->free();
        } else {
            $sth->execute(array($virus_lover,
                                       $spam_lover,
                                       $banned_files_lover,
                                       $bad_header_lover,
                                       $bypass_virus_checks,
                                       $bypass_spam_checks,
                                       $bypass_banned_checks,
                                       $bypass_header_checks,
                                       $discard_viruses,
                                       $discard_spam,
                                       $discard_banned_files,
                                       $discard_bad_headers,
                                       $spam_modifies_subj,
                                       $spam_tag_level,
                                       $spam_tag2_level,
                                       $spam_kill_level,
                                       $policy_id));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
        }

        $_SESSION['message'] = $lang['text_settings_updated'];
        header("Location: settings.php$msid");
        exit();
    /*
     *  Pressed the "Update Miscellaneous Settings" button
     *
     *
     */
    } elseif (isset($_POST['update_misc'])) {

        if (isset($_POST["reminder"])) {
            $reminder = (trim($_POST["reminder"]) == "yes" ? "Y" : "N");
            if ($reminder_threshold_count > 0) {
                $update = "UPDATE maia_users SET reminders = ? WHERE id = ?";
                $dbh->query($update, array($reminder, $euid));
            }
        }
        if (isset($_POST["charts"])) {
            $charts = (trim($_POST["charts"]) == "yes" ? "Y" : "N");
            if ($enable_charts) {
                $update = "UPDATE maia_users SET charts = ? WHERE id = ?";
                $dbh->query($update, array($charts, $euid));
            }
        }
        if (isset($_POST["spamtrap"])) {
            $spamtrap = (trim($_POST["spamtrap"]) == "yes" ? "Y" : "N");
            if ($enable_spamtraps) {
                $update = "UPDATE maia_users SET spamtrap = ? WHERE id = ?";
                $dbh->query($update, array($spamtrap, $euid));
            }
        }
        if (isset($_POST["auto_whitelist"])) {
            $auto_whitelist = (trim($_POST["auto_whitelist"]) == "yes" ? "Y" : "N");
            $update = "UPDATE maia_users SET auto_whitelist = ? WHERE id = ?";
            $dbh->query($update, array($auto_whitelist, $euid));
        }
        if (isset($_POST["items_per_page"])) {
            $items_per_page = $_POST["items_per_page"];
            $update = "UPDATE maia_users SET items_per_page = ? WHERE id = ?";
            $dbh->query($update, array($items_per_page, $euid));
        }
        if (isset($_POST["digest_interval"])) {
            $quarantine_digest_interval = intval($_POST["digest_interval"]) * 60; //adjust from hours displayed to minutes in database
            $sth = $dbh->prepare("UPDATE maia_users SET quarantine_digest_interval = ? WHERE id = ?");
            $sth->execute(array($quarantine_digest_interval, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }
        if (isset($_POST["language"])) {
            $language = trim($_POST["language"]);
            $sth = $dbh->prepare("UPDATE maia_users SET language = ? WHERE id = ?");
            $sth->execute(array($language, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            if ($uid == $euid) {
                $display_language = $language;
                $_SESSION["display_language"] = $display_language;
            }
        }
        if (isset($_POST["theme_id"])) {
            $theme_id = (trim($_POST["theme_id"]));
            $sth = $dbh->prepare("UPDATE maia_users SET theme_id = ? WHERE id = ?");
            $sth->execute(array($theme_id, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }
        if (isset($_POST["truncate_subject"])) {
            $truncate_subject = (trim($_POST["truncate_subject"]));
            $sth = $dbh->prepare("UPDATE maia_users SET truncate_subject = ? WHERE id = ?");
            $sth->execute(array($truncate_subject, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }
        if (isset($_POST["truncate_email"])) {
            $truncate_email = (trim($_POST["truncate_email"]));
            $sth = $dbh->prepare("UPDATE maia_users SET truncate_email = ? WHERE id = ?");
            $sth->execute(array($truncate_email, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }
        if (isset($_POST["discard_ham"])) {
            $discard_ham = (trim($_POST["discard_ham"]));
            $sth = $dbh->prepare("UPDATE maia_users SET discard_ham = ? WHERE id = ?");
            $sth->execute(array($discard_ham, $euid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }    
        if (isset($_POST["enable_user_autocreation"])) {
            $enable_user_autocreation = (trim($_POST["enable_user_autocreation"]));
            $domain_id = $_POST['domain_id'];
            $sth = $dbh->prepare("UPDATE maia_domains SET enable_user_autocreation = ? WHERE id = ?");
            $sth->execute(array($enable_user_autocreation, $domain_id));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();
        }

        $_SESSION['message'] = $lang['text_settings_updated'];
        header("Location: settings.php{$msid}tab=1");
        exit();

    /*
     * Pressed the "Add E-Mail Address" button
     *
     *
     */
    } elseif (isset($_POST['add_email_address'])) {

        if (isset($_POST["login"])) {
            $login = trim($_POST["login"]);
        } else {
            $login = "";
        }
        if (isset($_POST["authpass"])) {
            $password = trim($_POST["authpass"]);
            $password = stripslashes($password); // get rid of any escape characters
        } else {
            $password = "";
        }
        if (isset($_POST["domain"])) {
            $domain = trim($_POST["domain"]);
        } else {
            $domain = "";
        }

        if (($auth_method == "pop3" && !empty($routing_domain)) ||
            $auth_method == "ldap" || $auth_method == "exchange" ||
            $auth_method == "sql" || $auth_method == "internal" ||
            $auth_method == "external") {
            $user_name = $login;
            $address = "";
        } else {
            $address = $login;
            $user_name = "";
        }
        list($authenticated, $email) = auth($user_name, $password, $address, $domain);

        if ($authenticated === true) {
            $address_id = get_email_address_id($email);
            $old_owner = get_email_address_owner($address_id);
            if (!$old_owner) {
                add_email_address_to_user($euid, $email);
            } else {
                transfer_email_address_to_user($old_owner, $euid, $email);
            }
            $message = sprintf($lang['text_address_added'], $email);
        } else {
            $message = sprintf($lang['text_login_failed'], $login);
            if (PEAR::isError($authenticated)) {
              $message .= "<br>" . $authenticated->getMessage();
            }
        }
        $_SESSION['message'] = $message;
        header("Location: settings.php{$msid}tab=0");
        exit();

    /*
     * Pressed the "Update Login Credentials" button
     *
     *
     */
    } elseif (isset($_POST['change_login_info']) && $auth_method == "internal") {

        if (isset($_POST["new_login_name"])) {
            $new_login = trim($_POST["new_login_name"]);
        } else {
            $new_login = "";
        }
        if (isset($_POST["new_password"])) {
            $new_password = trim(stripslashes($_POST["new_password"]));
        } else {
            $new_password = "";
        }
        if (isset($_POST["confirm_new_password"])) {
            $confirm_new_password = trim(stripslashes($_POST["confirm_new_password"]));
        } else {
            $confirm_new_password = "";
        }
        if (empty($new_login)) {
            $message = $lang['text_login_name_empty'];
        } elseif (empty($new_password) || empty($confirm_new_password)) {
            $message = $lang['text_password_empty'];
        }elseif ($new_password != $confirm_new_password) {
            $message = $lang['text_password_mismatch'];
        } else {
            $sth = $dbh->prepare("SELECT id FROM maia_users WHERE user_name = ?");
            $res = $sth->execute(array($new_login));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            if ($row = $res->fetchrow()) {
                if ($row["id"] != $euid) {
                    $message = $lang['text_login_name_exists'];
                } else {
                    $sthupdate = $dbh->prepare("UPDATE maia_users SET password = ? WHERE id = ?");
                    $sthupdate->execute(array(md5($new_password), $euid));
                    $message = $lang['text_password_updated'];
                }
            } else {
                if ($new_login[0] == "@") {
                    $message = $lang['text_login_name_not_allowed'];
                } else {
                    $isthupdate = $dbh->prepare("UPDATE maia_users SET user_name = ?, password = ? WHERE id = ?");
                    $sthupdate->execute(array($new_login, md5($new_password), $euid));
                    $message = $lang['text_credentials_updated'];
                }
            }
            $sth->free();
        }
        $_SESSION['message'] = $message;
        header("Location: settings.php{$msid}tab=1");
        exit();

    /*
     * Pressed the "Make Primary" button
     *
     *
     */
    } elseif (isset($_POST["user_id"])) {
        $user_id = trim($_POST["user_id"]);
        $sth = $dbh->prepare("SELECT users.id, users.email " .
                  "FROM users, maia_users " .
                  "WHERE users.maia_user_id = maia_users.id " .
                  "AND users.id <> maia_users.primary_email_id " .
                  "AND users.maia_user_id = ?");
        $res = $sth->execute(array($user_id));
        if (PEAR::isError($sth)) {
            die($sth->getMessage());
        }
        while ($row = $res->fetchrow()) {
            $email_id = $row["id"];
            $address = $row["email"];
            if (isset($_POST["make_primary_" . $email_id])) {
                $sth2 = $dbh->prepare("UPDATE maia_users SET primary_email_id = ? WHERE id = ?");
                $sth2->execute(array($email_id, $user_id));
                if (PEAR::isError($sth)) {
                    die($sth->getMessage());
                }
                $sth2->free();
                $message = sprintf($lang['text_new_primary_email'], $address);
            }
        }
        $sth->free();
        $_SESSION['message'] = $message;
        header("Location: settings.php{$msid}tab=0");
        exit();


    /*
     * Setting a transport
     */

    } elseif (isset($_POST['transport']) && $super) {
      if (isset($_POST["transport"]) && isset($_POST['domain_id'])) {
          $transport = $_POST['transport'];
          $domain_id = $_POST['domain_id'];
          if (isset($_POST["routing_domain"]) && $super) {
            $routing_domain = $_POST["routing_domain"];
            $update = "UPDATE maia_domains set transport=?, routing_domain=? WHERE id=?";
            $res = $dbh->query($update, array($transport, $routing_domain, $domain_id));
          } else {
             // Right now, transport settings is limited to only $super,
             //but leaving this code branch in case we decide to change that.
            $sth = $dbh->prepare("UPDATE maia_domains set transport=? WHERE id=?");
            $res = $sth->execute(array($transport, $domain_id));
          }

          if (PEAR::isError($res)) {
            $message =  sprintf($lang['text_transport_error'], $res->getMessage());
          } else {
            $message = $lang['text_transport_set'];
          }
          $sth->free();
      } else {
        $message =  sprintf($lang['text_transport_error'], $lang['text_invalid_transport_form']);
      }
      $_SESSION['message'] = $message;
      header("Location: settings.php{$msid}tab=4");
      exit();

    /*
     * Pressed the "Grant" button
     *
     *
     */
    } elseif ($super && isset($_POST['grant'])) {

        if (isset($_POST["administrators"]) && isset($_POST["domain_id"])) {
            $admins = $_POST["administrators"];
            $domain_id = $_POST['domain_id'];

            foreach ($admins as $admin_id) {
                // Link the administrator to this domain
                $sth = $dbh->prepare("INSERT INTO maia_domain_admins (admin_id, domain_id) VALUES (?, ?)");
                $sth->execute(array($admin_id, $domain_id));
                if (PEAR::isError($sth)) {
                    die($sth->getMessage());
                }
                $sth->free();

                // Change the user's privilege level to Domain (A)dministrator
                $sth = $dbh->prepare("UPDATE maia_users SET user_level = 'A' WHERE id = ?");
                $sth->execute(array($admin_id));
                if (PEAR::isError($sth)) {
                    die($sth->getMessage());
                }
                $sth->free();

                $message = $lang['text_administrators_added'];
            }
        }

        $_SESSION['message'] = $message;
        header("Location: settings.php{$msid}tab=3");
        exit();

    /*
     * Pressed the "Revoke" button
     *
     *
     */
    } elseif ($super && isset($_POST['revoke']) && isset($_POST['revoke_id'])) {
        $domain_id = $_POST['domain_id'];
        $rids = $_POST['revoke_id'];

        foreach($rids as $key => $rid) {
            $sth = $dbh->perpare("DELETE FROM maia_domain_admins WHERE domain_id = ? AND admin_id = ?");
            $sth->execute(array($domain_id, $rid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            $sth->free();

            // If this administrator doesn't control any remaining domains,
            // demote him to a regular (U)ser.
            $sth2 = $dbh->prepare("SELECT domain_id FROM maia_domain_admins WHERE admin_id = ?");
            $res2 = $sth2->execute(array($rid));
            if (PEAR::isError($sth)) {
                die($sth->getMessage());
            }
            if (!$sth2->fetchrow()) {
                $sth3 = $dbh->prepare("UPDATE maia_users SET user_level = 'U' WHERE id = ?");
                $sth3->execute(array($rid));
                if (PEAR::isError($sth)) {
                    die($sth->getMessage());
                }
                $sth3->free();

            }
            $sth2->free();
        }
        $_SESSION['message'] = $lang['text_admins_revoked'];
        header("Location: settings.php{$msid}tab=2");
        exit();

    }
?>
