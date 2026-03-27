// Script by Hailey Enfield
// If an entry has an empty avatar_name it will update the database with the correct name.

list allowed_users = ["00000000-0000-0000-0000-000000000000", "00000000-0000-0000-0000-000000000000"];
string server_url = "https://YOUR-SITE-HERE.tld/avCron.php";
string API_KEY = "YOUR-API-KEY";
integer update_interval = 28800; // Interval between checks (8 hours)
integer debug_enabled = TRUE;
integer command_channel = 3; 
list pending_keys; 
string current_avatar_key; 
integer expecting_keys = FALSE; 
integer delay_update_interval = 5; 
integer batch_limit = 5; 
integer waiting_for_next_cycle = FALSE; 

debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

performCheck() {
    if (waiting_for_next_cycle == TRUE) {
        debug("Currently waiting for next cycle. Skipping check.");
        return; 
    }
    
    string post_data = "api_key=" + API_KEY + "&action=check_empty_names&limit=" + (string)batch_limit;
    debug("Requesting up to " + (string)batch_limit + " empty avatar names...");
    expecting_keys = TRUE;
    llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
}

default {
    state_entry() {
        llSetTimerEvent(update_interval);
        llListen(command_channel, "", llGetOwner(), ""); 
        llOwnerSay("Hails.CronServer is now online");
        debug("Debugging is enabled.");
        performCheck(); 
    }

    timer() {
        if (llGetListLength(pending_keys) > 0) {
            current_avatar_key = llStringTrim(llList2String(pending_keys, 0), STRING_TRIM);
            pending_keys = llDeleteSubList(pending_keys, 0, 0);

            if (current_avatar_key == "" || (key)current_avatar_key == NULL_KEY) {
                debug("Skipping invalid UUID entry: " + current_avatar_key);
                llSetTimerEvent(delay_update_interval);
                return;
            }

            debug("Looking up avatar name for UUID: " + current_avatar_key);
            llRequestAgentData(current_avatar_key, DATA_NAME);

            llSetTimerEvent(delay_update_interval); 
        } else if (waiting_for_next_cycle == FALSE) {
            debug("Batch complete; checking for more empty names.");
            performCheck();
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        if (waiting_for_next_cycle == TRUE) {
            debug("Ignoring response due to waiting for next cycle.");
            return;
        }

        debug("HTTP Response Status: " + (string)status);
        debug("JSON Received: " + body);

        if (status != 200) {
            debug("Error fetching data from server. Status: " + (string)status);
            return;
        }

        if (expecting_keys == TRUE) {
            expecting_keys = FALSE;

            if (body == "{\"empty_avatar_keys\":[]}") {
                debug("No entries with empty names found. Sleeping for next interval.");
                waiting_for_next_cycle = TRUE;
                llSetTimerEvent(update_interval);
                return;
            }

            integer start_index = llSubStringIndex(body, "[");
            integer end_index = llSubStringIndex(body, "]");

            if (start_index != -1 && end_index != -1 && end_index > start_index) {
                string clean_body = llGetSubString(body, start_index + 1, end_index - 1);

                pending_keys = llParseString2List(clean_body, ["\"", ","], []);
                
                integer i;
                list filtered_keys = [];
                integer count = llGetListLength(pending_keys);

                for (i = 0; i < count; ++i) {
                    string candidate = llStringTrim(llList2String(pending_keys, i), STRING_TRIM);

                    if (candidate != "" && candidate != "]" && (key)candidate != NULL_KEY) {
                        filtered_keys += [candidate];
                    }
                }

                pending_keys = filtered_keys;

                debug("Parsed keys to process: " + llDumpList2String(pending_keys, ", "));

                if (llGetListLength(pending_keys) > 0) {
                    llSetTimerEvent(delay_update_interval);
                } else {
                    debug("No entries found after parsing; sleeping for next interval.");
                    waiting_for_next_cycle = TRUE;
                    llSetTimerEvent(update_interval);
                }
            } else {
                debug("Error parsing JSON response: invalid indices.");
                waiting_for_next_cycle = TRUE;
                llSetTimerEvent(update_interval);
            }

            return;
        }

        if (llSubStringIndex(body, "\"success\":\"Avatar name updated successfully\"") != -1) {
            debug("Avatar name update acknowledged by server.");
            return;
        }

        if (llSubStringIndex(body, "\"error\"") != -1) {
            debug("Server returned an error: " + body);
            return;
        }

        debug("Unexpected response from server: " + body);
    }

    dataserver(key query_id, string avatar_name) {
        if (llStringLength(avatar_name) > 0) {
            string post_data = "api_key=" + API_KEY + "&action=update_avatar_name&avatar_key=" + current_avatar_key + "&avatar_name=" + llEscapeURL(avatar_name);
            string censored_post_data = "api_key=CENSORED-API-KEY&action=update_avatar_name&avatar_key=" + current_avatar_key + "&avatar_name=" + llEscapeURL(avatar_name);
            debug("Sending to server: " + censored_post_data);  
            llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
        } else {
            debug("Avatar name lookup failed for UUID: " + current_avatar_key);
        }
    }

    listen(integer channel, string name, key id, string message) {
        if (channel == command_channel && llToLower(message) == "toggle debug") {
            if (debug_enabled == TRUE) {
                debug_enabled = FALSE;
                llOwnerSay("Debugging is now disabled.");
            } else {
                debug_enabled = TRUE;
                llOwnerSay("Debugging is now enabled.");
            }
        }
    }
}
