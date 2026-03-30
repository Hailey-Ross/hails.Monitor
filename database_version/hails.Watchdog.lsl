// === CONFIG === //
integer RESTART_INTERVAL = 72000; 

list SCRIPTS_TO_RESET = [
    "hails.Monitor",
    "Script B",
    "Script C"
];

integer startTime;

resetTargets()
{
    integer i;
    integer count = llGetListLength(SCRIPTS_TO_RESET);

    for (i = 0; i < count; i++)
    {
        string scriptName = llList2String(SCRIPTS_TO_RESET, i);
        llOwnerSay("hails.Watchdog - Resetting: " + scriptName);
        llResetOtherScript(scriptName);
    }
}

default
{
    state_entry()
    {
        startTime = llGetUnixTime();
        llOwnerSay("hails.Watchdog - Cycle started at: " + (string)startTime);

        llSetTimerEvent(60.0);
    }

    timer()
    {
        integer now = llGetUnixTime();

        if ((now - startTime) >= RESTART_INTERVAL)
        {
            llOwnerSay("hails.Watchdog - 20 hours reached. Restarting scripts...");

            resetTargets();

            startTime = now;
            llOwnerSay("hails.Watchdog - New cycle started at: " + (string)startTime);
        }
    }

    on_rez(integer start_param)
    {
        llResetScript();
    }
}
