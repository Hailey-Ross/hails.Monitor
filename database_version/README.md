# hails.Monitor

Monitor avatar activity in Second Life and store data in a MySQL database. This version of the script is provided as-is. 🛠️

---

## 📦 What You'll Need:

- **Webserver:**
  - [Find a Web Host](https://letmegooglethat.com/?q=webhosting)
- **Database:** MySQL recommended.
- **SQL Client:**
  - [HeidiSQL](https://www.heidisql.com/)
  - [DBeaver](https://dbeaver.io/)
  - [Other Options](https://alternativeto.net/software/heidisql/)
- **Basic Database Knowledge:** You'll need to run SQL scripts and manage tables.

## ✅ Optional but Recommended:

- [Grafana](https://grafana.com/): Free tier works great for visualizing data.

---

## 🚨 Important Security Notes:

- **Don't host `config.php` in a public directory.** [Secure your config file](https://letmegooglethat.com/?q=How+to+securely+host+a+config+file).
- **Never share your API key publicly.** [Why API security matters](https://letmegooglethat.com/?q=API+Security).

---

## ⚡️ Example Setup

**Note:** The folder structure will follow the layout in this repo. Everything in the `PHP` folder should go in the desired directory on your webserver.

### 1. Download the Files:

- Grab the contents of the `PHP` folder and move them to your local machine.
- Copy the LSL scripts (`hails.Monitor.lsl`, `hails.Lookup.lsl`, `hails.CronServer.lsl`, `hails.HUDmon.lsl`) into your Second Life inventory.

### 2. Update Server URL:

- Update the `server_url` variable in the LSL scripts to point to your webserver hosting `av.php`:
  ```lsl
  string server_url = "https://YOUR-SITE-HERE.tld/av.php";
  ```
  Edit this in `hails.Lookup.lsl`, `hails.Monitor.lsl`, `hails.CronServer.lsl`, `hails.HUDmon.lsl`.

### 3. Create an API Key:

- Generate a key and update all relevant locations:
  ```lsl
  string API_KEY = "YOUR-SECRET-KEY";
  ```
  Edit in `config.php`, `hails.Lookup.lsl`, `hails.Monitor.lsl`, `hails.CronServer.lsl`, `hails.HUDmon.lsl`.

### 4. Upload to Webserver:

- Connect via FTP/SFTP.
- Upload the contents of the `PHP` folder to the root of your server.

### 5. Run the SQL Script:

- Open your SQL client (e.g., HeidiSQL, DBeaver).
- Execute the `run_me.sql` file found in the `SQL` folder to set up the database tables.

### 6. Secure Your Config:

- Include the `.htaccess` file in the same directory as `config.php` to restrict access.
- There's also an optional `.htaccess` file in the same folder as `av.php` if no `index` file is present.

### 7. Deploy the LSL Scripts:

- Place `hails.Monitor.lsl` and `hails.Lookup.lsl` in an object in Second Life.
  - `hails.Lookup.lsl` can also be used as a standalone lookup script.
- Deploy `hails.CronServer.lsl` in another object to handle periodic checks.
  - Toggle debug on channel 3: `/3 toggle debug`

### 8. Troubleshooting:

- If nothing happens:
  - Say `/2 toggle debug` in chat and resend the command after 5-10 seconds.
  - Check the output for error messages.
  - Check server logs and console for PHP errors.

### 9. Tighten Up Security:

- Revisit your API key setup and consider rotating keys periodically.
- Check out best practices for [API security](https://letmegooglethat.com/?q=API+Security).
