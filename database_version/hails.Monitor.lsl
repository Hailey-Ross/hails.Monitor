// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps and region
// Say "hails info" in public chat for Command List

list allowed_users = ["0fc458f0-50c4-4d6f-95a6-965be6e977ad"]; // Who else can check the visitor list? UUID's only
integer scan_interval = 5; // How often to scan
integer command_channel = 2; // IM Toggle command channel
integer max_avatar_count = 250; // Maximum number of visitors to output
integer batch_size = 20; // Number of avatars to send in each batch

// Database Connection strings
string server_url = "https://YOUR-SITE-HERE.tld/av.php"; // Secure HTTPS URL
string API_KEY = "YOUR-API-KEY-HERE"; // API Key for server communication

// DO NOT TOUCH BELOW HERE
list avatar_list = [];        // For database operations
list local_avatar_list = [];  // For show me command output
integer total_visitor_count = 0; 
string scanner_name; 
float last_notification_time = 0.0; 
integer waiting_for_response = FALSE;
integer debug_enabled = FALSE;
integer im_notifications_enabled = FALSE;
integer notification_cooldown = 60;
integer scanner_active = FALSE;
integer heartbeat_interval = 30;     // seconds
integer scanner_timeout = 90;        // must be greater than heartbeat_interval
integer last_heartbeat_sent = 0;
string scanner_key = "";
string active_region = "";

debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

sendBatchToServer() {
    string post_data = "api_key=" + API_KEY + "&action=store_batch&data=" + llDumpList2String(avatar_list, ",");
    string censor_post_data = "api_key=abc_CENSORED_xyz" + "&action=store_batch&data=" + llDumpList2String(avatar_list, ",");
    debug("Sending batch to server with data: " + censor_post_data);
    llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    // Clear the avatar_list after sending to maintain the recent visitors
    avatar_list = []; 
}

checkInRegion() {
    active_region = llGetRegionName();
    string object_name = llGetObjectName();

    string post_data =
        "api_key=" + API_KEY +
        "&action=scanner_checkin" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key) +
        "&owner_key=" + llEscapeURL((string)llGetOwner()) +
        "&object_name=" + llEscapeURL(object_name) +
        "&timeout_seconds=" + (string)scanner_timeout;

    llHTTPRequest(
        server_url,
        [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],
        post_data
    );
}

releaseRegion() {
    if (active_region == "" || scanner_key == "") {
        return;
    }

    string post_data =
        "api_key=" + API_KEY +
        "&action=scanner_release" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key);

    llHTTPRequest(
        server_url,
        [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],
        post_data
    );
}

default {
    state_entry() {
        scanner_name = "hails.Monitor";
        scanner_key = (string)llGetKey();
        active_region = llGetRegionName();
        scanner_active = FALSE;
        last_heartbeat_sent = 0;
    
        llOwnerSay(scanner_name + " starting in region: " + active_region);
        checkInRegion();
    
        llSetTimerEvent(scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    on_rez(integer start_param) {
        llResetScript();
    }
    
    changed(integer change) {
    if (change & CHANGED_REGION) {
        releaseRegion();

        avatar_list = [];
        local_avatar_list = [];
        total_visitor_count = 0;

        active_region = llGetRegionName();
        scanner_active = FALSE;
        checkInRegion();
    }

    if (change & CHANGED_OWNER) {
        releaseRegion();
        llResetScript();
    }
}

    timer() {
    integer now = llGetUnixTime();

    if ((now - last_heartbeat_sent) >= heartbeat_interval) {
        last_heartbeat_sent = now;
        checkInRegion();
    }

    if (!scanner_active) {
        debug("Scanner is inactive in this region. Skipping scan.");
        return;
    }

    list agents = llGetAgentList(AGENT_LIST_REGION, []);
    integer count = llGetListLength(agents);
    string region_name = llGetRegionName();

    debug("Timer event fired. Number of agents detected: " + (string)count);

    if (count == 0) {
        return;
    }

    integer i;
    for (i = 0; i < count; i++) {
        key avatar_key = llList2Key(agents, i);
        string avatar_name = llKey2Name(avatar_key);

        string first_seen = llDeleteSubString(llGetTimestamp(), -4, -1);
        string last_seen = llDeleteSubString(llGetTimestamp(), -4, -1);

        integer index = llListFindList(avatar_list, [avatar_name, (string)avatar_key]);
        debug("Avatar detected: " + avatar_name + ", UUID: " + (string)avatar_key + ", Index: " + (string)index);

        if (index == -1) {
            avatar_list += [avatar_name, (string)avatar_key, region_name, first_seen, last_seen];

            integer local_index = llListFindList(local_avatar_list, [avatar_name, (string)avatar_key]);
            if (local_index == -1) {
                local_avatar_list += [avatar_name, (string)avatar_key, region_name, first_seen, last_seen];
                total_visitor_count++;
            }

            if (llGetListLength(avatar_list) >= batch_size * 5) {
                sendBatchToServer();
            }

            if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                last_notification_time = llGetTime();
            }
        } else {
            avatar_list = llListReplaceList(avatar_list, [last_seen], index + 4, index + 4);
            local_avatar_list = llListReplaceList(local_avatar_list, [last_seen], index + 4, index + 4);

            if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                last_notification_time = llGetTime();
            }
        }
    }

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
            if (message == "show me") {
                integer count = llGetListLength(local_avatar_list); // Use local_avatar_list here
                if (count == 0) {
                    llInstantMessage(id, "No avatars have been detected.");
                } else {
                    string output = "Total unique visitors tracked: " + (string)total_visitor_count + "\n";
                    integer visitors_to_show = total_visitor_count;

                    if (visitors_to_show > max_avatar_count) {
                        visitors_to_show = max_avatar_count;
                    }
                    output += "Displaying " + (string)visitors_to_show + " recent visitor(s):\n";
                    
                    // Displaying the recent visitors based on max_avatar_count
                    integer start_index = count - (visitors_to_show * 5);
                    if (start_index < 0) {
                        start_index = 0; // Ensure we don't go negative
                    }

                    integer i;
                    for (i = start_index; i < count; i += 5) {
                        string avatar_name = llList2String(local_avatar_list, i);
                        string avatar_key = llList2String(local_avatar_list, i + 1);
                        string first_seen = llList2String(local_avatar_list, i + 2);
                        string last_seen = llList2String(local_avatar_list, i + 3);
                        output += "Name: " + avatar_name + "\nUUID: " + avatar_key + "\nFirst seen: " + first_seen + "\nLast seen: " + last_seen + "\n\n";

                        if (llStringLength(output) > 950) {
                            llInstantMessage(id, output);
                            output = ""; // Reset the output for more messages
                        }
                    }
                    if (output != "") {
                        llInstantMessage(id, output); // Send any remaining output
                    }
                }
            } else if (message == "hails clear") {
                local_avatar_list = []; // Clear the local list
                total_visitor_count = 0;
                llInstantMessage(id, "Avatar list has been cleared.");
            } else if (message == "hails reset") {
                avatar_list = [];
                local_avatar_list = []; // Clear the local list as well
                total_visitor_count = 0;
                llInstantMessage(id, "Rebooting " + scanner_name + "..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    scanner_name + " Commands:\n" +
                    "• 'show me' - Displays detected avatars and their first and last detection times.\n" +
                    "• 'hails clear' - Clears the visitor list.\n" +
                    "• 'hails reset' - Resets the script.\n" +
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
        debug("HTTP Response Status: " + (string)status);
        debug("Server response: " + body);
    
        if (status != 200) {
            debug("HTTP request failed.");
            return;
        }
    
        string lower_body = llToLower(body);
    
        if (llSubStringIndex(lower_body, "\"action\":\"scanner_checkin\"") != -1) {
            // optional if you wish to add actions to response
        }
    
        if (llSubStringIndex(lower_body, "\"is_active\":1") != -1) {
            if (!scanner_active) {
                scanner_active = TRUE;
                llOwnerSay(scanner_name + " is now ACTIVE in region " + active_region + ".");
                llSetObjectDesc("" + active_region + " Server");
            }
        } else if (llSubStringIndex(lower_body, "\"is_active\":0") != -1) {
            if (scanner_active) {
                scanner_active = FALSE;
                llOwnerSay(scanner_name + " is now INACTIVE in region " + active_region + " because another scanner is active.");
                llSetObjectDesc("Not currently activated in this Sim.");
            } else {
                scanner_active = FALSE;
            }
        }
    }
}
