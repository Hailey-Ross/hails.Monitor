// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps
// Commands:"show me", "hails clear", "hails reset", "hails info" and "toggle im"
// The time stuff is ugly, don't look pls

list avatar_list = [];
integer scan_interval = 5;
list allowed_users = ["0fc458f0-50c4-4d6f-95a6-965be6e977ad", "00000000-0000-0000-0000-000000000000"];
integer im_notifications_enabled = FALSE;
integer command_channel = 2;

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

                if (llListFindList(avatar_list, [avatar_name, (string)avatar_key]) == -1) {
                    avatar_list += [avatar_name, (string)avatar_key, detection_time];
                    if (im_notifications_enabled) {
                        llInstantMessage(llGetOwner(), "New avatar detected: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
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
                    llInstantMessage(id, "Detected " + (string)(count / 3) + " visitor(s):");
                    integer i;
                    for (i = 0; i < count; i += 3) {
                        string avatar_name = llList2String(avatar_list, i);
                        string avatar_key = llList2String(avatar_list, i + 1);
                        string detection_time = llList2String(avatar_list, i + 2);
                        llInstantMessage(id, "Name: " + avatar_name + " \nFirst seen: " + detection_time);
                    }
                }
            } else if (message == "hails clear") {
                avatar_list = [];
                llInstantMessage(id, "Avatar list has been cleared.");
            } else if (message == "hails reset") {
                avatar_list = [];
                llInstantMessage(id, "Rebooting Hails.Scanner..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    "Hails.Scanner Commands:\n" +
                    "• 'show me' - Displays detected avatars and their first detection time.\n" +
                    "• 'hails clear' - Clears the avatar list.\n" +
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
