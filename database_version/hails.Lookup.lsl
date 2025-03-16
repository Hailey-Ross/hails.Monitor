// Script Created by Hailey Enfield
// Queries the server for avatar info by UUID

string server_url = "https://YOUR-URL-HERE.com/av.php"; // URL to your PHP script that handles avatar queries
string API_KEY = "YOUR-API-KEY"; // Define your API key here
integer waiting_for_response = FALSE; // Prevent multiple simultaneous requests

requestAvatarInfo(string avatar_key) {
    if (!waiting_for_response) {
        waiting_for_response = TRUE; // Prevent further requests until this one is complete
        string post_data = "api_key=" + API_KEY + "&action=query" + "&avatar_key=" + avatar_key;
        llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    }
}

default {
    state_entry() {
        llListen(2, "", llGetOwner(), "");
        llListen(0, "", "", "");
    }

    listen(integer channel, string name, key id, string message) {
        if (id == llGetOwner()) {
            if (llGetSubString(message, 0, 10) == "get avatar ") {
                string avatar_key = llGetSubString(message, 11, -1);
                requestAvatarInfo(avatar_key);
            }
            else if (llToLower(message) == "hails info") {
                llOwnerSay("Lookup Commands:\n• 'get avatar <UUID>' - Retrieves information for the specified avatar UUID.");
            }
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        if (waiting_for_response) {
            if (status == 200) {
                list response_data = llJson2List(body);

                string avatar_name = "";
                string region_name = "";
                string first_seen = "";
                string last_seen = "";

                if (llListFindList(response_data, ["name"]) != -1) {
                    avatar_name = llList2String(response_data, llListFindList(response_data, ["name"]) + 1);
                }
                if (llListFindList(response_data, ["region"]) != -1) {
                    region_name = llList2String(response_data, llListFindList(response_data, ["region"]) + 1);
                }
                if (llListFindList(response_data, ["first_seen"]) != -1) {
                    first_seen = llList2String(response_data, llListFindList(response_data, ["first_seen"]) + 1);
                }
                if (llListFindList(response_data, ["last_seen"]) != -1) {
                    last_seen = llList2String(response_data, llListFindList(response_data, ["last_seen"]) + 1);
                }

                string output = "Visitor Info:\n" +
                                "Name: " + avatar_name + "\n" +
                                "Region: " + region_name + "\n" +
                                "First Seen: " + first_seen + "\n" +
                                "Last Seen: " + last_seen;

                llOwnerSay(output);  
            } else {
                llOwnerSay("Error fetching avatar data from the server.");
            }
            waiting_for_response = FALSE; 
        }
    }
}
