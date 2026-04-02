// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps and region
// Say "hails info" in public chat for Command List

list allowed_users = ["11111111-2222-3333-4444-555555555555","aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"]; // Who else can check the visitor list? UUID's only
integer scan_interval = 12; // How often to scan
integer command_channel = 2; // IM Toggle command channel
integer max_avatar_count = 250; // Maximum number of visitors to output
integer batch_size = 25; // Number of avatars to send in each batch

// Database Connection strings
string server_url = "https://YOUR-SITE-URL-HERE.tld/av.php"; // Secure HTTPS URL
string API_KEY = "YOUR-API-KEY-HERE"; // API Key for server communication

// DO NOT TOUCH BELOW HERE
list avatar_list = [];        // Active avatars currently in-region only: [uuid, name, first_seen, last_seen]
integer total_visitor_count = 0; 
string scanner_name = "hails.HUDMonitor"; 
float last_notification_time = 0.0; 
integer waiting_for_response = FALSE;
integer debug_enabled = TRUE;
integer im_notifications_enabled = FALSE;
integer notification_cooldown = 60;
integer scanner_active = FALSE;
integer heartbeat_interval = 30;
integer scanner_timeout = 90;
integer last_heartbeat_sent = 0;
string scanner_key = "";
string active_region = "";

// Texture UUID
string texture_uuid = "b18295e3-facb-0a25-61ae-d0b49073ea65"; // Set texture UUID

// Debug function to handle whether to output or not
debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

checkInRegion() {
    active_region = llGetRegionName();

    string post_data =
        "api_key=" + API_KEY +
        "&action=scanner_checkin" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key) +
        "&owner_key=" + llEscapeURL((string)llGetOwner()) +
        "&object_name=" + llEscapeURL(llGetObjectName()) +
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

// Function to send avatar data in batches to the server
sendBatchToServer() {
    if (llGetListLength(avatar_list) == 0) {
        return;
    }

    integer count = llGetListLength(avatar_list);
    integer i;
    string post_prefix = "api_key=" + API_KEY + "&action=store_batch&data=";
    string censored_prefix = "api_key=CENSORED_API_KEY&action=store_batch&data=";
    string region_name = llGetRegionName();

    list batch = [];
    integer batch_avatar_count = 0;

    for (i = 0; i < count; i += 4) {
        string avatar_key = llList2String(avatar_list, i);
        string avatar_name = llList2String(avatar_list, i + 1);
        string first_seen = llList2String(avatar_list, i + 2);
        string last_seen = llList2String(avatar_list, i + 3);

        batch += [avatar_name, avatar_key, region_name, first_seen, last_seen];
        batch_avatar_count++;

        if (batch_avatar_count >= batch_size) {
            string post_data = post_prefix + llDumpList2String(batch, ",");
            string censored_post_data = censored_prefix + llDumpList2String(batch, ",");
            debug("Sending batch to server with data: " + censored_post_data);
            llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
            batch = [];
            batch_avatar_count = 0;
        }
    }

    if (llGetListLength(batch) > 0) {
        string post_data = post_prefix + llDumpList2String(batch, ",");
        string censored_post_data = censored_prefix + llDumpList2String(batch, ",");
        debug("Sending batch to server with data: " + censored_post_data);
        llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    }
}



default {
    on_rez(integer start_param) { 
        avatar_list = [];
        llResetScript();
    }
    changed(integer change) {
        if (change & CHANGED_REGION) {
            releaseRegion();
            avatar_list = [];
            total_visitor_count = 0;
            active_region = llGetRegionName();
            scanner_active = FALSE;
            llOwnerSay(scanner_name + " has detected a region change. Rebooting..");
            llResetScript();
        }
    
        if (change & (CHANGED_OWNER | CHANGED_INVENTORY)) {
            avatar_list = [];
            releaseRegion();
            llOwnerSay(scanner_name + " has detected a change. Rebooting..");
            llResetScript();
        }
    }
    state_entry() {
        scanner_key = (string)llGetKey();
        active_region = llGetRegionName();
        scanner_active = FALSE;
        last_heartbeat_sent = 0;
    
        llSetTexture(texture_uuid, ALL_SIDES);
    
        llOwnerSay(scanner_name + " starting in region: " + active_region);
        checkInRegion();
    
        if (im_notifications_enabled) {
            llOwnerSay(scanner_name + " is online. IM notifications are enabled.");
        } else {
            llOwnerSay(scanner_name + " is online. IM notifications are disabled.");
        }
    
        llSetTimerEvent(scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    timer() {
        integer now = llGetUnixTime();
    
        if ((now - last_heartbeat_sent) >= heartbeat_interval) {
            last_heartbeat_sent = now;
            checkInRegion();
        }
    
        if (!scanner_active) {
            debug("Scanner is inactive in this region. Skipping scan.");
            llSetColor(<1.0, 0.0, 0.5>, ALL_SIDES);
            return;
        }
    
        llSetColor(<1.0, 1.0, 1.0>, ALL_SIDES);
    
        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);
    
        debug("Timer event fired. Number of agents detected: " + (string)count);

        integer i;
        string now_ts = llDeleteSubString(llGetTimestamp(), -4, -1);

        // Remove avatars who have left
        integer list_count = llGetListLength(avatar_list);
        for (i = list_count - 4; i >= 0; i -= 4) {
            string stored_uuid = llList2String(avatar_list, i);

            if (llListFindList(agents, [(key)stored_uuid]) == -1) {
                avatar_list = llDeleteSubList(avatar_list, i, i + 3);
            }
        }

        // Add new avatars and update last_seen for avatars still present
        for (i = 0; i < count; i++) {
            key avatar_key = llList2Key(agents, i);
            string avatar_uuid = (string)avatar_key;
            integer row_start = -1;
            integer j;
            integer current_count = llGetListLength(avatar_list);

            for (j = 0; j < current_count; j += 4) {
                if (llList2String(avatar_list, j) == avatar_uuid) {
                    row_start = j;
                    j = current_count;
                }
            }

            integer debug_index;

            if (row_start == -1) {
                debug_index = -1;
            } else {
                debug_index = row_start / 4;
            }
            
            debug("Avatar detected: UUID: " + avatar_uuid + ", Index: " + (string)debug_index);

            if (row_start == -1) {
                string avatar_name = llKey2Name(avatar_key);

                avatar_list += [avatar_uuid, avatar_name, now_ts, now_ts];
                total_visitor_count++;
    
                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + avatar_uuid + ")");
                    last_notification_time = llGetTime();
                }
            } else {
                string avatar_name = llList2String(avatar_list, row_start + 1);
                avatar_list = llListReplaceList(avatar_list, [now_ts], row_start + 3, row_start + 3);
    
                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + avatar_uuid + ")");
                    last_notification_time = llGetTime();
                }
            }
        }

        sendBatchToServer();
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
            if (message == "hails reset") {
                avatar_list = []; // Clear the avatar list
                total_visitor_count = 0; // Reset visitor count
                llInstantMessage(id, "Rebooting " + scanner_name + "..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    scanner_name + " Commands:\n" +
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
            debug("Error fetching avatar data from the server. Status: " + (string)status);
            waiting_for_response = FALSE;
            return;
        }
    
        string lower_body = llToLower(body);
    
        if (llSubStringIndex(lower_body, "\"is_active\":1") != -1) {
            if (!scanner_active) {
                scanner_active = TRUE;
                llOwnerSay(scanner_name + " is now ACTIVE in region " + active_region + ".");
                llSetObjectDesc("" + active_region + " Server");
            }
            llSetColor(<1.0, 1.0, 1.0>, ALL_SIDES);
        } else if (llSubStringIndex(lower_body, "\"is_active\":0") != -1) {
            if (scanner_active) {
                llOwnerSay(scanner_name + " is now INACTIVE in region " + active_region + " because another scanner is active.");
                llSetObjectDesc("Not currently activated in this Sim.");
            }
            scanner_active = FALSE;
            llSetColor(<1.0, 0.0, 0.5>, ALL_SIDES);
        }
    
        waiting_for_response = FALSE;
    }
}
