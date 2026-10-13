<?php
// tets.php
session_start();
include('../db.php');
$conn = new mysqli($servername, $username, $password, $database);
$login_error = '';

if (isset($_POST["admin_name"]) && isset($_POST["heslo"])) {
    $admin = $_POST["admin_name"];
    $ask = $conn->query("SELECT password FROM admin WHERE user_name = '$admin'");
    if ($ask && $ask->num_rows == 1) {
        $row = $ask->fetch_assoc();
        if (password_verify($_POST["heslo"], $row["password"])) {
            $_SESSION["admin"] = $admin;
        } else {
            $login_error = "Nesprávné heslo.";
        }
    } else {
        $login_error = "Uživatel nebyl nalezen.";
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Code Scanner & Administrace</title>
  <style>
      body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
      header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 10px 20px;
          background: #f5f5f5;
          border-bottom: 1px solid #ddd;
      }
      header .userInfo {
          font-size: 16px;
          color: #333;
      }
      header .logoutContainer a {
          text-decoration: none;
          font-size: 16px;
          color: #333;
          border: 1px solid #333;
          padding: 5px 10px;
          border-radius: 4px;
      }
      #video { width: 320px; max-width: 100%; height: auto; margin: 20px auto; display: block; border: 2px solid #333; }
      canvas { display: none; }
      #output { margin-top: 10px; font-size: 18px; font-weight: bold; color: #333; text-align: center; }
      form { margin-top: 20px; text-align: center; }
      #manualForm {
          margin: 20px auto;
          text-align: center;
      }
      #manualForm input[type="text"] {
          width: 60px;
          font-size: 20px;
          text-align: center;
      }
      #manualForm button {
          font-size: 16px;
          padding: 5px 10px;
          margin-left: 10px;
          cursor: pointer;
      }
      #confirmOverlay {
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0, 0, 0, 0.5);
          display: none;
          align-items: center;
          justify-content: center;
          z-index: 1000;
      }
      #confirmPopup {
          position: relative;
          background: #fff;
          padding: 20px 40px;
          border-radius: 8px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.3);
          text-align: center;
          max-width: 90%;
      }
      #closeOverlay {
          position: absolute;
          top: 10px;
          right: 10px;
          cursor: pointer;
          font-size: 24px;
          color: #333;
      }
      #confirmPopup button {
          padding: 10px 20px;
          font-size: 18px;
          margin: 10px;
          cursor: pointer;
      }
      #ticketInfo {
          margin-bottom: 20px;
          font-size: 20px;
          color: #333;
      }
  </style>
</head>
<body>
<?php if (!isset($_SESSION["admin"])): ?>
  <h2 style="text-align:center;">Přihlášení administrátora</h2>
  <?php if ($login_error): ?>
    <p style="color:red; text-align:center;"><?php echo $login_error; ?></p>
  <?php endif; ?>
  <form method="post" action="tets.php">
      <label for="admin_name">Uživatelské jméno:</label>
      <input type="text" name="admin_name" id="admin_name" required><br><br>
      <label for="heslo">Heslo:</label>
      <input type="password" name="heslo" id="heslo" required><br><br>
      <button type="submit">Přihlásit</button>
  </form>
<?php else: ?>
  <header>
      <div class="userInfo">Přihlášen jako: <strong><?php echo htmlspecialchars($_SESSION["admin"]); ?></strong></div>
      <div class="logoutContainer"><a id="logout" href="logout.php">Odhlásit se</a></div>
  </header>
  
  <h1 style="text-align:center; margin-top:20px;">QR Code Scanner</h1>
  <video id="video" autoplay muted playsinline></video>
  <canvas id="canvas"></canvas>
  <div id="output">Čekám na QR kód…</div>

  <div id="manualForm">
      <label for="manualTicket">Číslo lístku:</label>
      <input type="text" id="manualTicket" maxlength="3" placeholder="123">
      <button id="searchBtn">Hledej</button>
  </div>
  
  <div id="confirmOverlay">
      <div id="confirmPopup">
          <span id="closeOverlay">&times;</span>
          <div id="ticketInfo"></div>
          <button id="confirmBtn">Potvrdit použití</button>
          <button id="cancelBtn" style="display:none;">Obnovit lístek</button>
      </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
  
  <script>
      var adminName = "<?php echo isset($_SESSION["admin"]) ? $_SESSION["admin"] : ''; ?>";
      
      const video = document.getElementById('video');
      const canvas = document.getElementById('canvas');
      const output = document.getElementById('output');
      const context = canvas.getContext('2d');
      const confirmOverlay = document.getElementById('confirmOverlay');
      const closeOverlay = document.getElementById('closeOverlay');
      const confirmBtn = document.getElementById('confirmBtn');
      const cancelBtn = document.getElementById('cancelBtn');
      const ticketInfo = document.getElementById('ticketInfo');
      const searchBtn = document.getElementById('searchBtn');
      const manualTicket = document.getElementById('manualTicket');
  
      let scanning = true; 
      let currentTicketId = "";
      let currentEmail = "";
  
      function handleCameraError(err) {
          console.error("Chyba kamery:", err);
          output.textContent = "Chyba při přístupu ke kameře: " + err.message;
      }
  
      function startCamera() {
          navigator.mediaDevices.getUserMedia({
              video: {
                  facingMode: { ideal: 'environment' },
                  width: { ideal: 1280 },
                  height: { ideal: 720 }
              }
          }).then(function(stream) {
              video.srcObject = stream;
              video.play();
              video.addEventListener('loadedmetadata', () => {
                  requestAnimationFrame(scanQR);
              });
          }).catch(handleCameraError);
      }
  
      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
          output.textContent = "Přistupuji ke kameře…";
          startCamera();
      } else {
          output.textContent = "Váš prohlížeč nepodporuje přístup ke kameře.";
      }
  
      function scanQR() {
          if (video.videoWidth > 0 && video.videoHeight > 0 && scanning) {
              canvas.width = video.videoWidth;
              canvas.height = video.videoHeight;
              context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight);
              const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
              const qrCode = jsQR(imageData.data, imageData.width, imageData.height);
              if (qrCode) {
                  output.textContent = `QR kód načten: ${qrCode.data}`;
                  scanning = false;
                  processTicket(qrCode.data);
              } else {
                  output.textContent = "Čekám na QR kód…";
              }
          }
          requestAnimationFrame(scanQR);
      }
  
      function processTicket(qrCodeValueOrManual) {
          let ticketNumber = "";
          let emailFromQR = "";
          try {
              let url = new URL(qrCodeValueOrManual);
              let parts = url.pathname.split('/');
              if (parts.length < 4) {
                  output.textContent = "QR kód není validní.";
                  scanning = true;
                  return;
              }
              ticketNumber = parts[2];
              emailFromQR = decodeURIComponent(parts[3]);
          } catch (e) {
              ticketNumber = qrCodeValueOrManual;
          }
  
          currentTicketId = ticketNumber;
          currentEmail = emailFromQR || "";
  
          output.textContent = "Kontroluji lístek...";
  
          fetch('ajax.php?mode=check&check=' + encodeURIComponent(ticketNumber) + '&email=' + encodeURIComponent(currentEmail))
              .then(response => response.json())
              .then(data => {
                  if (data.status === 'valid') {
                      document.body.style.backgroundColor = 'lightgreen';
                      output.textContent = data.message;
                      currentEmail = data.email;
                      currentTicketId = data.ticketId;
                      ticketInfo.textContent = "Email: " + currentEmail + " | ID lístku: " + currentTicketId;
                      confirmOverlay.style.display = 'flex';
                      confirmBtn.style.display = 'inline-block';
                      cancelBtn.style.display = 'none';
                  } else if (data.status === 'used') {
                      document.body.style.backgroundColor = 'red';
                      output.textContent = data.message;
                      currentEmail = data.email;
                      currentTicketId = data.ticketId;
                      ticketInfo.textContent = "Email: " + currentEmail + " | ID lístku: " + currentTicketId;
                      confirmOverlay.style.display = 'flex';
                      if (adminName.toLowerCase() === 'pospa' || adminName.toLowerCase() === 'vikousek') {
                          cancelBtn.style.display = 'inline-block';
                          cancelBtn.textContent = "Obnovit lístek";
                      } else {
                          cancelBtn.style.display = 'none';
                      }
                      confirmBtn.style.display = 'none';
                  } else {
                      output.textContent = data.message;
                      setTimeout(() => {
                          document.body.style.backgroundColor = '';
                          output.textContent = "Čekám na QR kód…";
                          scanning = true;
                      }, 1000);
                  }
              })
              .catch(error => {
                  output.textContent = "Chyba při ověřování: " + error;
                  scanning = true;
              });
      }
  
      searchBtn.onclick = function() {
          let manualVal = manualTicket.value.trim();
          if (manualVal === "" || isNaN(manualVal)) {
              output.textContent = "Zadejte prosím platné číslo lístku.";
              return;
          }
          processTicket(manualVal);
      };
        closeOverlay.onclick = function() {
          confirmOverlay.style.display = 'none';
          scanning = true;
      };
            confirmBtn.onclick = function() {
          output.textContent = "Potvrzuji lístek...";
          fetch('ajax.php?mode=confirm&check=' + encodeURIComponent(currentTicketId) + '&email=' + encodeURIComponent(currentEmail))
              .then(response => response.json())
              .then(data => {
                  if (data.status === 'success') {
                      document.body.style.backgroundColor = 'lightgreen';
                  } else {
                      document.body.style.backgroundColor = 'red';
                  }
                  output.textContent = data.message;
                  confirmOverlay.style.display = 'none';
                  setTimeout(() => {
                      document.body.style.backgroundColor = '';
                      output.textContent = "Čekám na QR kód…";
                      scanning = true;
                  }, 1000);
              })
              .catch(error => {
                  output.textContent = "Chyba při potvrzování: " + error;
                  confirmOverlay.style.display = 'none';
                  scanning = true;
              });
      };
      
      cancelBtn.onclick = function() {
          output.textContent = "Obnovuji lístek...";
          fetch('ajax.php?mode=cancel&check=' + encodeURIComponent(currentTicketId) + '&email=' + encodeURIComponent(currentEmail))
              .then(response => response.json())
              .then(data => {
                  if (data.status === 'success') {
                      document.body.style.backgroundColor = 'lightgreen';
                  } else {
                      document.body.style.backgroundColor = 'red';
                  }
                  output.textContent = data.message;
                  confirmOverlay.style.display = 'none';
                  setTimeout(() => {
                      document.body.style.backgroundColor = '';
                      output.textContent = "Čekám na QR kód…";
                      scanning = true;
                  }, 1000);
              })
              .catch(error => {
                  output.textContent = "Chyba při obnovení: " + error;
                  confirmOverlay.style.display = 'none';
                  scanning = true;
              });
      };
  </script>
<?php endif; ?>
</body>
</html>
