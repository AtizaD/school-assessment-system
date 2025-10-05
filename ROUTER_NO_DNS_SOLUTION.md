# Router Without Custom DNS - Complete Solution

## Your Situation
- Router connected but **doesn't support custom DNS settings**
- Router has DHCP enabled (will conflict with PC DHCP)
- Need devices to automatically get DNS settings
- PC IP: 192.168.0.124 (WiFi)

## Solution Overview

**Disable router DHCP → Use PC DHCP instead**

Your PC will become the DHCP + DNS server for the network.

---

## Step 1: Disable Router DHCP

1. **Log into router admin panel**
   - Usually: `http://192.168.0.1` or `http://192.168.1.1`
   - Enter admin credentials

2. **Find DHCP Settings**
   - Look for: LAN → DHCP Server
   - Or: Network → DHCP Settings

3. **Disable DHCP Server**
   - Uncheck "Enable DHCP Server" or set to "Disabled"
   - Save settings
   - **Reboot router**

⚠️ **Important**: After disabling router DHCP, devices won't get IPs until PC DHCP is running!

---

## Step 2: Install Open DHCP Server on PC

### Download

**Option A: Official Site**
- http://dhcpserver.de/cms/

**Option B: SourceForge**
- https://sourceforge.net/projects/dhcpserver/

### Install

1. **Extract to:** `C:\OpenDHCPServer`

2. **Configure:** Edit `C:\OpenDHCPServer\OpenDHCPServer.ini`

```ini
[LISTEN_ON]
192.168.0.124

[SETTINGS]
FilterVendorClass=False
FilterUserClass=False
FilterSubnetSelection=False

[RANGE_SET]
DHCPRange=192.168.0.10-192.168.0.199
SubnetMask=255.255.255.0

[GLOBAL_OPTIONS]
Router=192.168.0.1
DNS=192.168.0.124
SubnetMask=255.255.255.0
DomainName=local
LeaseTime=86400
```

**Key Settings:**
- `LISTEN_ON=192.168.0.124` ← Your PC WiFi IP
- `Router=192.168.0.1` ← Your actual router IP (gateway)
- `DNS=192.168.0.124` ← Your PC (Acrylic DNS)
- `DHCPRange=192.168.0.10-192.168.0.199` ← IPs to assign

### Install as Windows Service

Run **as Administrator**:

```cmd
cd C:\OpenDHCPServer
OpenDHCPServerService.exe -i
net start "Open DHCP Server"
```

### Add Firewall Rule

```cmd
netsh advfirewall firewall add rule name="Open DHCP Server" dir=in action=allow protocol=UDP localport=67
```

---

## Step 3: Install Acrylic DNS Service

Currently you only have Acrylic software, not the service.

### Install Service

Run **as Administrator**:

```cmd
cd "C:\Program Files (x86)\Acrylic DNS Proxy"
InstallAcrylicService.bat
```

### Configure Acrylic

**Edit:** `C:\Program Files (x86)\Acrylic DNS Proxy\AcrylicConfiguration.ini`

Find and set:
```ini
LocalIPv4BindingAddress=0.0.0.0
```

**Edit:** `C:\Program Files (x86)\Acrylic DNS Proxy\AcrylicHosts.txt`

Add at the end:
```
192.168.0.124 basslocal.com
192.168.0.124 www.basslocal.com
```

### Start Service

```cmd
net start "Acrylic DNS Proxy"
```

### Add Firewall Rules

```cmd
netsh advfirewall firewall add rule name="Acrylic DNS UDP" dir=in action=allow protocol=UDP localport=53
netsh advfirewall firewall add rule name="Acrylic DNS TCP" dir=in action=allow protocol=TCP localport=53
```

---

## Step 4: Fix PC Hosts File

**Edit:** `C:\Windows\System32\drivers\etc\hosts`

Uncomment the line:
```
192.168.0.124 basslocal.com www.basslocal.com
```

---

## Complete Setup Script

Save as `setup_router_no_dns.bat` and run **as Administrator**:

```batch
@echo off
echo ================================================
echo Router Without DNS - Complete Setup
echo ================================================

REM Step 1: Install Acrylic DNS Service
echo Step 1: Installing Acrylic DNS Service...
cd "C:\Program Files (x86)\Acrylic DNS Proxy"
call InstallAcrylicService.bat

REM Step 2: Add Acrylic firewall rules
echo Step 2: Adding Acrylic firewall rules...
netsh advfirewall firewall add rule name="Acrylic DNS UDP" dir=in action=allow protocol=UDP localport=53
netsh advfirewall firewall add rule name="Acrylic DNS TCP" dir=in action=allow protocol=TCP localport=53

REM Step 3: Start Acrylic
echo Step 3: Starting Acrylic DNS...
net start "Acrylic DNS Proxy"

REM Step 4: Install Open DHCP Server Service (if exists)
echo Step 4: Installing DHCP Server...
if exist "C:\OpenDHCPServer\OpenDHCPServerService.exe" (
    cd C:\OpenDHCPServer
    OpenDHCPServerService.exe -i

    REM Add DHCP firewall rule
    netsh advfirewall firewall add rule name="Open DHCP Server" dir=in action=allow protocol=UDP localport=67

    REM Start DHCP
    net start "Open DHCP Server"

    echo ================================================
    echo Setup Complete!
    echo ================================================
    echo.
    echo Services Running:
    echo - Acrylic DNS (port 53) - Resolves basslocal.com
    echo - Open DHCP Server (port 67) - Assigns IPs and DNS
    echo.
    echo Network Configuration:
    echo - DHCP Range: 192.168.0.10-199
    echo - Gateway: 192.168.0.1 (router)
    echo - DNS: 192.168.0.124 (your PC)
    echo.
    echo Devices will automatically get:
    echo - IP: 192.168.0.10-199 (auto-assigned)
    echo - Gateway: 192.168.0.1
    echo - DNS: 192.168.0.124 ✅
    echo.
    echo Access: https://basslocal.com/
    echo ================================================
) else (
    echo.
    echo ================================================
    echo WARNING: Open DHCP Server not found!
    echo ================================================
    echo.
    echo Please download from: http://dhcpserver.de/cms/
    echo Extract to: C:\OpenDHCPServer
    echo.
    echo After installing, edit C:\OpenDHCPServer\OpenDHCPServer.ini:
    echo.
    echo [LISTEN_ON]
    echo 192.168.0.124
    echo.
    echo [GLOBAL_OPTIONS]
    echo Router=192.168.0.1
    echo DNS=192.168.0.124
    echo.
    echo Then run this script again.
    echo ================================================
)

pause
```

---

## Network Architecture

```
[Router - 192.168.0.1]
    ├─ DHCP: DISABLED ❌
    ├─ Acts as gateway only
    │
    └─ [Your PC - 192.168.0.124]
        ├─ Open DHCP Server (port 67)
        │   └─ Assigns: DNS=192.168.0.124, Gateway=192.168.0.1
        ├─ Acrylic DNS (port 53)
        │   └─ Resolves: basslocal.com → 192.168.0.124
        └─ Apache/XAMPP (ports 80, 443)
            └─ Serves: basslocal.com

[Student Device]
    ├─ Connects to WiFi/Ethernet
    ├─ Gets IP: 192.168.0.50 (from PC DHCP) ✅
    ├─ Gets Gateway: 192.168.0.1 (from PC DHCP) ✅
    ├─ Gets DNS: 192.168.0.124 (from PC DHCP) ✅
    └─ Opens: https://basslocal.com/ → Works! ✅
```

---

## Installation Checklist

- [ ] Disable router's DHCP server
- [ ] Reboot router
- [ ] Download Open DHCP Server
- [ ] Extract to `C:\OpenDHCPServer`
- [ ] Configure `OpenDHCPServer.ini` (DNS=192.168.0.124)
- [ ] Install Open DHCP Server service
- [ ] Add DHCP firewall rule
- [ ] Install Acrylic DNS service
- [ ] Configure Acrylic to listen on 0.0.0.0
- [ ] Add basslocal.com to AcrylicHosts.txt
- [ ] Add Acrylic firewall rules
- [ ] Start both services
- [ ] Uncomment basslocal.com in PC hosts file
- [ ] Test: Connect device → Should get IP automatically
- [ ] Test: Check device DNS settings = 192.168.0.124
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
UDP    192.168.0.124:67 *:*     (DHCP Server)
```

### 3. Connect Device and Check

**Android:**
```
Settings → About Phone → Status → IP Address
```

Should show:
- IP: 192.168.0.x (auto-assigned)
- Gateway: 192.168.0.1
- DNS: 192.168.0.124 ✅

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

### 5. Test from PC

```cmd
nslookup basslocal.com 192.168.0.124
```

Should return: `192.168.0.124`

---

## Troubleshooting

### Devices not getting IP

- Check router DHCP is disabled
- Check Open DHCP Server service is running
- Check firewall allows port 67
- Restart Open DHCP Server service
- Check `OpenDHCPServer.ini` range and IP

### Devices get IP but can't resolve basslocal.com

- Check Acrylic service is running
- Check `AcrylicHosts.txt` has the entry
- Verify DNS in DHCP config is 192.168.0.124
- Test: `nslookup basslocal.com 192.168.0.124` from PC
- Check Acrylic firewall rules

### Can't access internet from devices

- Check `Router=192.168.0.1` in OpenDHCPServer.ini
- Router must have internet connection
- Test: ping 8.8.8.8 from device

### PC can't resolve basslocal.com

- Uncomment line in `C:\Windows\System32\drivers\etc\hosts`
- Should be: `192.168.0.124 basslocal.com www.basslocal.com`
- Flush DNS: `ipconfig /flushdns`

---

## Why This Works

1. **Router DHCP disabled** → No conflict
2. **PC DHCP assigns DNS=192.168.0.124** → Automatic assignment
3. **Acrylic DNS resolves basslocal.com** → Custom domain works
4. **Router still acts as gateway** → Internet still works
5. **No manual device configuration** → Just connect and work! ✅

---

**This solution gives you complete control over DNS while keeping router for internet connectivity!** 🎯
