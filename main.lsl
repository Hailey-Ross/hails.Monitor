// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps
// Say "hails info" in public chat for Command List
// The time stuff is ugly, don't look pls

list allowed_users = ["00000000-0000-0000-0000-000000000000", "00000000-0000-0000-0000-000000000000"]; // Who else can check the visitor list? UUID's only
integer scan_interval = 5; // How often to scan
integer im_notifications_enabled = FALSE; // Default state of IM Notifications
integer command_channel = 2; // IM Toggle command channel
integer max_avatar_count = 250; // Maximum number of avatars to track
integer notification_cooldown = 60; // Cooldown period in seconds for IM notifications


//          DO NOT TOUCH
// Unless you know what you are doing

list avatar_list = [];
integer total_visitor_count = 0; 
float last_notification_time = 0.0; 

default {
    state_entry() {
        if (im_notifications_enabled) {
            llOwnerSay("Hails.Scanner is online. \nIM notifications are enabled.");
        } else {
            llOwnerSay("Hails.Scanner is online. \nIM notifications are disabled.");
        }
        llSetTimerEvent(scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    timer() {
        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);

        if (count == 0) {
            llWhisper(0, "No avatars detected in the region.");
        } else {
            integer i;
            for (i = 0; i < count; ++i) {
                key avatar_key = llList2Key(agents, i);
                string avatar_name = llKey2Name(avatar_key);
                string date = llGetDate();
                float pacific_time = llGetWallclock();
                float utc_time = pacific_time + (7 * 3600);

                if (utc_time >= 86400) {
                    utc_time -= 86400;
                    date = llGetSubString(llGetDate(), 0, 9);
                }

                integer hours = (integer)utc_time / 3600;
                integer minutes = ((integer)utc_time % 3600) / 60;
                integer seconds = (integer)utc_time % 60;

                string formatted_hours;
                if (hours < 10) {
                    formatted_hours = "0" + (string)hours;
                } else {
                    formatted_hours = (string)hours;
                }

                string formatted_minutes;
                if (minutes < 10) {
                    formatted_minutes = "0" + (string)minutes;
                } else {
                    formatted_minutes = (string)minutes;
                }

                string formatted_seconds;
                if (seconds < 10) {
                    formatted_seconds = "0" + (string)seconds;
                } else {
                    formatted_seconds = (string)seconds;
                }

                string formatted_time = formatted_hours + ":" + formatted_minutes + ":" + formatted_seconds;
                string detection_time = date + " " + formatted_time + " UTC";

                integer index = llListFindList(avatar_list, [avatar_name, (string)avatar_key]);
                if (index == -1) {
                    avatar_list += [avatar_name, (string)avatar_key, detection_time, detection_time];
                    total_visitor_count++;

                    if (llGetListLength(avatar_list) > max_avatar_count * 4) {
                        avatar_list = llListReplaceList(avatar_list, [], 0, 3);
                    }

                    if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                        llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                        last_notification_time = llGetTime();
                    }
                } else {
                    avatar_list = llListReplaceList(avatar_list, [detection_time], index + 3, index + 3);
                    if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                        llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                        last_notification_time = llGetTime();
                    }
                }
            }
        }
    }

    listen(integer channel, string name, key id, string message) {
        message = llToLower(message);

        if (channel == command_channel && id == llGetOwner()) {
            if (message == "toggle im") {
                im_notifications_enabled = !im_notifications_enabled;
                if (im_notifications_enabled) {
                    llOwnerSay("IM notifications are now enabled.");
                } else {
                    llOwnerSay("IM notifications are now disabled.");
                }
            }
        } else if (channel == 0 && (id == llGetOwner() || llListFindList(allowed_users, [id]) != -1)) {
            if (message == "show me") {
                integer count = llGetListLength(avatar_list);
                if (count == 0) {
                    llInstantMessage(id, "No avatars have been detected.");
                } else {
                    string output = "Total unique visitors tracked: " + (string)total_visitor_count + "\n";
                    output += "Displaying " + (string)(count / 4) + " recent visitor(s):\n";
                    integer i;
                    for (i = 0; i < count; i += 4) {
                        string avatar_name = llList2String(avatar_list, i);
                        string avatar_key = llList2String(avatar_list, i + 1);
                        string first_seen = llList2String(avatar_list, i + 2);
                        string last_seen = llList2String(avatar_list, i + 3);
                        output += "Name: " + avatar_name + "\nFirst seen: " + first_seen + "\nLast seen: " + last_seen + "\n\n";

                        if (llStringLength(output) > 950) {
                            llInstantMessage(id, output);
                            output = "";
                        }
                    }
                    if (output != "") {
                        llInstantMessage(id, output);
                    }
                }
            } else if (message == "hails clear") {
                avatar_list = [];
                total_visitor_count = 0;
                llInstantMessage(id, "Avatar list has been cleared.");
            } else if (message == "hails reset") {
                avatar_list = [];
                total_visitor_count = 0;
                llInstantMessage(id, "Rebooting Hails.Scanner..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    "Hails.Scanner Commands:\n" +
                    "• 'show me' - Displays detected avatars and their first and last detection times.\n" +
                    "• 'hails clear' - Clears the visitor list.\n" +
                    "• 'hails reset' - Resets the script.\n" +
                    "• '/"
                    + (string)command_channel + " toggle im' - (Owner Only) Toggles IM notifications for new avatar detection."
                );
            }
        } else {
            llInstantMessage(id, "Access denied.");
        }
    }
}
