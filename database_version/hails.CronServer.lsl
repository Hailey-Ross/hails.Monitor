// Script by Hailey Enfield
// If an entry has an empty avatar_name it will update the database with the correct name.

list allowed_users = ["00000000-0000-0000-0000-000000000000", "00000000-0000-0000-0000-000000000000"];
string server_url = "https://YOUR-URL-HERE.com/avCron.php"; 
string API_KEY = "YOUR-API-HERE"; 
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
            current_avatar_key = llList2String(pending_keys, 0);
            pending_keys = llDeleteSubList(pending_keys, 0, 0); 
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

        if (status == 200 && expecting_keys == TRUE) { 
            expecting_keys = FALSE; 

            if (body == "{\"empty_avatar_keys\":[]}") {
                debug("No entries with empty names found. Sleeping for next interval.");
                waiting_for_next_cycle = TRUE; 
                llSetTimerEvent(update_interval); 
                return;
            }

            integer start_index = llSubStringIndex(body, "[") + 1;
            integer end_index = llSubStringIndex(body, "]");
            
            if (start_index >= 1 && end_index > start_index) {
                string clean_body = llDeleteSubString(body, 0, start_index);
                clean_body = llDeleteSubString(clean_body, end_index - start_index, -1);
                
                pending_keys = llParseString2List(clean_body, ["\"", ","], []); 
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
                llSetTimerEvent(update_interval); 
            }
        } else if (status == 200 && llSubStringIndex(body, "\"success\":\"Batch update completed successfully\"") != -1) {
            debug("Name update acknowledged by server.");
        } else {
            debug("Error fetching data from server. Status: " + (string)status);
        }
    }

    dataserver(key query_id, string avatar_name) {
        if (llStringLength(avatar_name) > 0) {
            string post_data = "api_key=" + API_KEY + "&action=update_avatar_name&avatar_key=" + current_avatar_key + "&avatar_name=" + llEscapeURL(avatar_name);
            debug("Sending to server: " + post_data);  
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
