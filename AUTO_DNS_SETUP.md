# Automatic DNS Setup (No Manual Device Configuration)

## Your Requirement
- Devices connect via Ethernet/WiFi
- They automatically get DNS settings
- **No manual configuration on each device**

## Solution: DHCP Server with DNS Option

You need a DHCP server that assigns:
- IP address (automatic)
- Gateway (automatic)
- **DNS server = Your PC IP** (automatic)

---

## Option 1: Use Your Router's DHCP (Easiest)

If you're keeping the router connected, configure it to advertise your PC as the DNS server.

### Steps:

1. **Log into your router** (usually `192.168.0.1`)
   - Username/Password: Usually on router label or manual

2. **Find DHCP Settings**
   - Usually under: LAN → DHCP Server → DNS Settings
   - Different routers have different menus

3. **Set Primary DNS to your PC IP:**
   ```
   Primary DNS: 192.168.0.124 (or 192.168.0.200)
   Secondary DNS: 8.8.8.8 (Google DNS as backup)
   ```

4. **Save and reboot router**

5. **Renew devices' IP:**
   - Disconnect and reconnect WiFi/Ethernet
   - Or: Android → WiFi → Forget → Reconnect

**Result:** All devices automatically get DNS = 192.168.0.124

---

## Option 2: Windows DHCP Server (If Router is Disconnected)

If you disconnected the router and devices connect directly to your PC, use Windows DHCP.

### Problem with Windows ICS DHCP:
Windows Internet Connection Sharing (ICS) has a built-in DHCP server, but it **always** uses the host PC (192.168.137.1) as DNS. You **cannot** change this to point to Acrylic.

### Solution: Install Third-Party DHCP Server

#### A) Open DHCP Server (Free, Recommended)

**1. Download:**
- https://sourceforge.net/projects/dhcpserver/
- Or: http://dhcpserver.de/cms/

**2. Install to:** `C:\OpenDHCPServer`

**3. Configure:** Edit `C:\OpenDHCPServer\OpenDHCPServer.ini`

```ini
[LISTEN_ON]
192.168.0.200

[SETTINGS]
FilterVendorClass=False
FilterUserClass=False
FilterSubnetSelection=False

[RANGE_SET]
DHCPRange=192.168.0.10-192.168.0.199
SubnetMask=255.255.255.0

[GLOBAL_OPTIONS]
Router=192.168.0.200
DNS=192.168.0.200
SubnetMask=255.255.255.0
DomainName=local
LeaseTime=86400
```

**Key settings:**
- `DNS=192.168.0.200` ← Your PC IP (Acrylic DNS)
- `Router=192.168.0.200` ← Your PC acts as gateway
- `DHCPRange=192.168.0.10-192.168.0.199` ← IPs to assign

**4. Install as Windows Service:**

Run as Administrator:

```cmd
cd C:\OpenDHCPServer
OpenDHCPServerService.exe -i
net start "Open DHCP Server"
```

**5. Add Firewall Rule:**

```cmd
netsh advfirewall firewall add rule name="Open DHCP Server" dir=in action=allow protocol=UDP localport=67
```

**Result:** Devices automatically get:
- IP: 192.168.0.10-199 (auto-assigned)
- Gateway: 192.168.0.200
- DNS: 192.168.0.200 (Acrylic DNS) ✅

---

## Option 3: Dual DHCP Server (Router + Your PC)

**Warning:** This can cause conflicts! Use carefully.

If router is connected but you want to override DNS:

### A) Configure Router for Small Range

Router DHCP:
```
Range: 192.168.0.10 - 192.168.0.100
DNS: 192.168.0.1 (router's default)
```

### B) Configure Open DHCP Server for Different Range

Your PC DHCP:
```
Range: 192.168.0.101 - 192.168.0.200
DNS: 192.168.0.200 (your Acrylic DNS)
```

**Problem:** Devices might get IP from either DHCP server randomly.

**Better approach:** Disable router DHCP and only use your PC's DHCP.

---

## Option 4: Router DNS Hijacking (Advanced)

If your router supports custom firmware (OpenWrt, DD-WRT):

1. **Install custom firmware**
2. **Configure dnsmasq** to forward all DNS to your PC
3. Devices use router DNS → Router forwards to your PC

This is more complex but very reliable.

---

## Recommended Setup for Your Case

Since you mentioned the router will be disconnected:

### Setup: PC as DHCP + DNS Server

```
[Your PC - 192.168.0.200]
    ├─ Open DHCP Server (port 67)
    │   └─ Assigns DNS: 192.168.0.200
    ├─ Acrylic DNS (port 53)
    │   └─ Resolves: basslocal.com → 192.168.0.200
    └─ Apache/XAMPP (ports 80, 443)
        └─ Serves: basslocal.com

[Student Device] (Ethernet connected)
    ├─ Plugs in cable
    ├─ Gets IP: 192.168.0.50 (auto from DHCP)
    ├─ Gets Gateway: 192.168.0.200 (auto from DHCP)
    ├─ Gets DNS: 192.168.0.200 (auto from DHCP) ✅
    └─ Opens browser: https://basslocal.com/ → Works! ✅
```

---

## Complete Setup Script

Save as `setup_auto_dns.bat` and run as Administrator:

```batch
@echo off
echo ================================================
echo Setting up Automatic DNS Assignment
echo ================================================

REM Step 1: Set your Ethernet IP
echo Step 1: Setting Ethernet IP to 192.168.0.200...
netsh interface ip set address name="Ethernet 6" static 192.168.0.200 255.255.255.0

REM Step 2: Install Acrylic DNS Service
echo Step 2: Installing Acrylic DNS...
cd "C:\Program Files (x86)\Acrylic DNS Proxy"
call InstallAcrylicService.bat

REM Step 3: Add Acrylic firewall rules
echo Step 3: Adding Acrylic firewall rules...
netsh advfirewall firewall add rule name="Acrylic DNS UDP" dir=in action=allow protocol=UDP localport=53
netsh advfirewall firewall add rule name="Acrylic DNS TCP" dir=in action=allow protocol=TCP localport=53

REM Step 4: Start Acrylic
echo Step 4: Starting Acrylic DNS...
net start "Acrylic DNS Proxy"

REM Step 5: Install Open DHCP Server Service (if exists)
echo Step 5: Installing DHCP Server...
if exist "C:\OpenDHCPServer\OpenDHCPServerService.exe" (
    cd C:\OpenDHCPServer
    OpenDHCPServerService.exe -i

    REM Add DHCP firewall rule
    netsh advfirewall firewall add rule name="Open DHCP Server" dir=in action=allow protocol=UDP localport=67

    REM Start DHCP
    net start "Open DHCP Server"
) else (
    echo WARNING: Open DHCP Server not found at C:\OpenDHCPServer
    echo Please download from: http://dhcpserver.de/cms/
    echo After installing, edit OpenDHCPServer.ini to set DNS=192.168.0.200
)

echo.
echo ================================================
echo Setup Complete!
echo ================================================
echo.
echo Your PC IP: 192.168.0.200
echo DNS Server: Acrylic (port 53)
echo DHCP Server: Open DHCP (port 67)
echo.
echo Devices will automatically get:
echo - IP: 192.168.0.10-199
echo - Gateway: 192.168.0.200
echo - DNS: 192.168.0.200
echo.
echo Just plug in devices and they will work!
echo Access: https://basslocal.com/
echo ================================================
pause
```

---

## Installation Checklist

- [ ] Set PC Ethernet to static IP (192.168.0.200)
- [ ] Install Acrylic DNS Service
- [ ] Configure Acrylic to listen on 0.0.0.0
- [ ] Add basslocal.com to AcrylicHosts.txt
- [ ] Start Acrylic service
- [ ] Download and install Open DHCP Server
- [ ] Configure OpenDHCPServer.ini with DNS=192.168.0.200
- [ ] Install Open DHCP Server service
- [ ] Start DHCP service
- [ ] Add firewall rules (DNS port 53, DHCP port 67)
- [ ] Test: Connect device → Should get IP automatically
- [ ] Test: Open basslocal.com → Should work

---

## Testing

### 1. Verify Services Running

```cmd
sc query "Acrylic DNS Proxy"
sc query "Open DHCP Server"
```

Both should show: `STATE: 4 RUNNING`

### 2. Verify Listening Ports

```cmd
netstat -an | findstr ":53 :67"
```

Should show:
```
UDP    0.0.0.0:53       *:*     (Acrylic DNS)
UDP    0.0.0.0:67       *:*     (DHCP Server)
```

### 3. Connect Device and Check

On connected device:

**Android:**
```
Settings → About Phone → Status → IP Address
```

Should show:
- IP: 192.168.0.x (auto-assigned)
- Gateway: 192.168.0.200
- DNS: 192.168.0.200

**iOS:**
```
Settings → WiFi → (i) → IP Address
```

### 4. Test DNS Resolution

From device browser:
```
https://basslocal.com/
```

Should load your school system!

---

## Troubleshooting

### Devices not getting IP

- Check DHCP service is running
- Check firewall allows port 67
- Restart DHCP service
- Check OpenDHCPServer.ini range is correct

### Devices get IP but can't resolve basslocal.com

- Check Acrylic service is running
- Check AcrylicHosts.txt has the entry
- Verify DNS in DHCP config is 192.168.0.200
- Test: `nslookup basslocal.com 192.168.0.200` from PC

### Devices get IP from wrong DHCP server

- Disable router DHCP if router is connected
- Check only one DHCP server is active
- Restart device to get fresh DHCP lease

---

**With this setup, devices just plug in and automatically work - no manual DNS configuration needed!** 🎯
