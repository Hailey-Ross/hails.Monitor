//Script Created by Hailey Enfield
//Site: https://u.hails.cc/Links
//Github: https://github.com/Hailey-Ross/hails.Monitor
//PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// LSL Script: Sim-wide Avatar Scanner (with allowed users receiving messages)
// Scans the entire sim, stores avatars with detection timestamps, and responds to "show me" and "hails clear" commands for allowed users.

list avatar_list = []; // Store avatars, UUIDs, and timestamps
integer scan_interval = 5; // Scan interval in seconds
list allowed_users = ["UUID1", "UUID2"]; // Add UUIDs of users you want to allow to use commands

default {
    state_entry() {
        llOwnerSay("Hails.Scanner is online.");
        llSetTimerEvent(scan_interval); // Set the timer for periodic scans
        llListen(0, "", llGetOwner(), ""); // Listen for commands from the owner only
    }

    timer() {
        // Use llGetAgentList to get all avatars in the sim
        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);

        if (count == 0) {
            llWhisper(0, "No avatars detected in the region.");;
        } else {
            integer i;
            for (i = 0; i < count; ++i) {
                key avatar_key = llList2Key(agents, i);
                string avatar_name = llKey2Name(avatar_key);

                // Get the current date in UTC
                string date = llGetDate();

                // Get Pacific Time in seconds since midnight using llGetWallclock()
                float pacific_time = llGetWallclock();

                // Adjust for UTC: Add 7 hours for PDT
                float utc_time = pacific_time + (7 * 3600); // Adjusting for PDT (add 7 hours)

                // Handle time overflow (if adding time goes past 24 hours)
                if (utc_time >= 86400) {
                    utc_time -= 86400; // Subtract a full day (24 hours in seconds)
                    date = llGetSubString(llGetDate(), 0, 9); // Adjust to the next day
                }

                integer hours = (integer)utc_time / 3600;
                integer minutes = ((integer)utc_time % 3600) / 60;
                integer seconds = (integer)utc_time % 60;

                // Properly format time into HH:MM:SS using if statements for leading zeroes
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

                // Avoid adding duplicates
                if (llListFindList(avatar_list, [avatar_name, (string)avatar_key]) == -1) {
                    avatar_list += [avatar_name, (string)avatar_key, detection_time]; // Store name, UUID, and detection time in UTC
                }
            }
        }
    }

    listen(integer channel, string name, key id, string message) {
        // Check if the sender is allowed to use the commands
        if (id == llGetOwner() || llListFindList(allowed_users, [id]) != -1) {
            // Respond to the owner's or allowed user's "show me" command
            if (llToLower(message) == "show me") {
                integer count = llGetListLength(avatar_list);
                if (count == 0) {
                    llInstantMessage(id, "No avatars have been detected.");
                } else {
                    llInstantMessage(id, "Detected " + (string)(count / 3) + " visitor(s):");
                    integer i;
                    for (i = 0; i < count; i += 3) { // Iterate through the list (name, UUID, timestamp)
                        string avatar_name = llList2String(avatar_list, i);
                        string avatar_key = llList2String(avatar_list, i + 1);
                        string detection_time = llList2String(avatar_list, i + 2);
                        llInstantMessage(id, "Name: " + avatar_name + " \nFirst seen: " + detection_time);
                    }
                }
            }
            // Respond to the "hails clear" command to clear the avatar list
            else if (llToLower(message) == "hails clear") {
                avatar_list = []; // Clear the avatar list
                llInstantMessage(id, "Avatar list has been cleared.");
            }
            else if (llToLower(message) == "hails reset") {
                avatar_list = []; // Clear the avatar list
                llInstantMessage(id, "Rebooting Hails.Scanner..");
                llResetScript();
            }
        } else {
            // If the user is not allowed
            llInstantMessage(id, "Access denied.");
        }
    }
}
