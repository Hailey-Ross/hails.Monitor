// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps and region
// Say "hails info" in public chat for Command List

// Place in object and wear on HUD
// This version of the scaript is a WIP.

list allowed_users = ["00000000-0000-0000-0000-000000000000"]; // Who else can check the visitor list? UUID's only
integer scan_interval = 12; // How often to scan
integer command_channel = 2; // IM Toggle command channel

// Database Connection strings
string server_url = "https://YOUR-SITE-HERE.tld/av.php"; // Secure HTTPS URL
string API_KEY = "YOUR-API-KEY-HERE"; // API Key for server communication

// DO NOT TOUCH BELOW HERE
list avatar_list = [];        // For database operations
integer total_visitor_count = 0; 
string scanner_name = "hails.Monitor"; 
float last_notification_time = 0.0; 
integer waiting_for_response = FALSE;
integer debug_enabled = FALSE;
integer im_notifications_enabled = FALSE;
integer notification_cooldown = 60;
integer standby_mode = FALSE; // Standby mode flag
integer max_avatar_count = 250; // Maximum number of visitors to output
integer batch_size = 20; // Number of avatars to send in each batch

// Texture UUID
string texture_uuid = "e836dda9-0e87-94db-abac-3596231e26aa"; // Set texture UUID

// Debug function to handle whether to output or not
debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

// Function to send avatar data in batches to the server
sendBatchToServer() {
    string post_data = "api_key=" + API_KEY + "&action=store_batch&data=" + llDumpList2String(avatar_list, ",");
    debug("Sending batch to server with data: " + post_data);
    llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    // Clear the avatar_list after sending to maintain the recent visitors
    avatar_list = []; 
}

// Function to check if in standby mode based on the region
checkStandbyMode() {
    string region_name = llGetRegionName();
    if (region_name == "Region 1" || region_name == "Region 2") { // add the region your scanner(s) are in here
        standby_mode = TRUE;
        debug("Standby mode enabled in region: " + region_name); // Debugging only
        llSetColor(<1.0, 0.0, 0.0>, ALL_SIDES); // Set color to red
    } else {
        standby_mode = FALSE;
        debug("Standby mode disabled in region: " + region_name); // Debugging only
        llSetColor(<0.0, 1.0, 0.0>, ALL_SIDES); // Set color to green
    }
}

default {
    on_rez(integer start_param) { llResetScript(); }
    changed(integer change) {
        if (change & (CHANGED_OWNER | CHANGED_INVENTORY | CHANGED_REGION)) {
            llOwnerSay(scanner_name + " has detected a change. \nRebooting..");
            llResetScript(); } }
    state_entry() {
        scanner_name = "hails.Monitor";
        llSetTexture(texture_uuid, ALL_SIDES); // Set the object's texture
        checkStandbyMode(); // Check if the script should be in standby mode
        if (im_notifications_enabled) {
            llOwnerSay(scanner_name + " is online. \nIM notifications are enabled.");
        } else {
            llOwnerSay(scanner_name + " is online. \nIM notifications are disabled.");
        }
        llSetTimerEvent(scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    timer() {
        checkStandbyMode(); // Check standby mode on each timer event

        if (standby_mode) {
            return; // Exit if in standby mode
        }

        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);
        string region_name = llGetRegionName(); // Restored region name functionality

        debug("Timer event fired. Number of agents detected: " + (string)count); // Debugging

        if (count == 0) {
            llWhisper(0, "No avatars detected in the region.");
        } else {
            integer i; // Declare i here
            for (i = 0; i < count; i++) { // Loop syntax corrected
                key avatar_key = llList2Key(agents, i);
                string avatar_name = llKey2Name(avatar_key);
                string date = llGetDate();
                float pacific_time = llGetWallclock();
                float utc_time = pacific_time + (7 * 3600);

                // Clean up the timestamps before sending to the database
                string first_seen = llDeleteSubString(llGetTimestamp(), -4, -1);
                string last_seen = llDeleteSubString(llGetTimestamp(), -4, -1);

                integer hours = (integer)utc_time / 3600;
                integer minutes = ((integer)utc_time % 3600) / 60;
                integer seconds = (integer)utc_time % 60;

                string formatted_time = (string)hours + ":" + (string)minutes + ":" + (string)seconds;
                string detection_time = date + " " + formatted_time; // Removed "UTC" to prevent the error

                // Check if the avatar is already in the avatar_list
                integer index = llListFindList(avatar_list, [avatar_name, (string)avatar_key]);
                debug("Avatar detected: " + avatar_name + ", UUID: " + (string)avatar_key + ", Index: " + (string)index); // Debugging

                if (index == -1) {
                    // Avatar is new, add it to the avatar_list
                    avatar_list += [avatar_name, (string)avatar_key, region_name, first_seen, last_seen];
                    total_visitor_count++; // Increment only when a new visitor is added

                    if (llGetListLength(avatar_list) >= batch_size * 5) {
                        sendBatchToServer(); // Send batch if the limit is reached
                    }

                    if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                        llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                        last_notification_time = llGetTime();
                    }
                } else {
                    // Avatar exists, update the last seen time in the avatar_list
                    avatar_list = llListReplaceList(avatar_list, [detection_time], index + 3, index + 3);
                    debug("Updated last seen time for: " + avatar_name); // Debugging
                    // Notify that the visitor was updated
                    if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                        llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                        last_notification_time = llGetTime();
                    }
                }
            }
        }

        // Send any remaining avatars after the timer event
        if (llGetListLength(avatar_list) > 0) {
            sendBatchToServer();
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
            } else if (message == "toggle debug") {
                debug_enabled = !debug_enabled;
                if (debug_enabled) {
                    llOwnerSay("Debugging is now enabled.");
                } else {
                    llOwnerSay("Debugging is now disabled.");
                }
            }
        } else if (channel == 0 && (id == llGetOwner() || llListFindList(allowed_users, [id]) != -1)) {
            if (message == "hudmon reset") {
                avatar_list = []; // Clear the avatar list
                total_visitor_count = 0; // Reset visitor count
                llInstantMessage(id, "Rebooting " + scanner_name + "..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    scanner_name + " Commands:\n" +
                    "• 'hudmon reset' - Resets the script.\n" +
                    "• '/"
                    + (string)command_channel + " toggle im' - (Owner Only) Toggles IM notifications for new avatar detection.\n" +
                    "• '/"
                    + (string)command_channel + " toggle debug' - (Owner Only) Toggles debugging output."
                );
            }
        } else {
            llInstantMessage(id, "Access denied.");
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        // Handle the server's response for avatar queries
        debug("HTTP Response Status: " + (string)status); // Debug: Show HTTP status

        if (status == 200) {
            debug("Server response: " + body);  // Debug: Show the server response
        } else {
            debug("Error fetching avatar data from the server. Status: " + (string)status);  // Debug: Show error status
        }
        waiting_for_response = FALSE; // Allow future requests after receiving a response
    }
}
