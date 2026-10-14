<?php
include('db.php');

$conn = new mysqli($servername, $username, $password, $database);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zobrazení lístků</title>
    <link href="https://fonts.googleapis.com/css2?family=Calistoga&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="/ikony/organizator.svg">
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .info {
            width: 20cm;
            margin: 0 auto 1cm;
            font-style: italic;
        }

        .ticket {
            overflow: hidden;
            width: 20cm;
            height: 7.2cm;
            margin: 0.5cm auto;
            border: 0.07cm dashed rgba(40, 114, 52, 1);
            border-radius: 15px;
            display: flex;
            padding-bottom: 0.5cm;
            background-image: url('bcg.svg'); 
            background-size: cover; 
            background-repeat: no-repeat;
            background-position: center; 
        }


        .number,
        .right-image {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px;

        }
        .content {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px;
            height: 100%;

        }
        .number p {
            margin: 0;
        }

        .details {
            height: 100%;
            width: 8.3cm;
            background-image: url('/img/ticket1.png');
            background-size: 100%;
            background-repeat: no-repeat;
            background-position: center;
            margin-left: 10px;
        }

        .fr {
            margin-bottom: 0.38cm;
            font-size: 1.25rem;
        }

        .ticket table {
            padding-left: 0.2cm;
        }

        .details td {
            background-color: white;
            border-radius: 20%;
        }

        .nz {
            margin-top: 0.2cm;
            padding-left: 0.2cm;
            font-size: 1.8rem;
            font-weight: bold;
            color: rgba(40, 114, 52, 0.5);
            font-family: 'Calistoga', sans-serif;
            padding-bottom: 0;
            padding-top: 0;
        }

        .right-image img {
            height: 6.5cm;
            width: auto;
        }

        .nz2 {
            font-size: 2rem;
            font-weight: 800;
            font-family: Arial, Helvetica, sans-serif;
            color: rgba(40, 114, 52, 1);
            font-family: 'Calistoga', sans-serif;
        }

        .ikon {
            width: 1.4rem;
        }


        /*forms***********************************/
        .container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        form {
            text-align: center;
        }

        h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        input {
            padding: 0.5rem;
            font-size: 1rem;
        }

        button {
            padding: 0.5rem 1rem;
            font-size: 1rem;
            background-color: rgba(255, 182, 41, 1);
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>

<body>

    <?php
    if (isset($_GET["ticket"])) {
        $id = $_GET["ticket"];
        echo '<div class="container">
                <form method="GET">
                <h1>Pro přístup k lístkům zadejte mail</h1>
                <input type="mail" name="mail" placeholder="Zadejte e-mail" required>
                <input type="hidden" name="id" value="' . $id . '">
                <button>ověřit</button>
                </form>
                </div>';
    }

    if (isset($_GET["mail"]) && isset($_GET["id"])) {
        $mail = $_GET["mail"];
        $id = $_GET["id"];

        $ask = mysqli_query($conn, "SELECT mail, name FROM ticket WHERE id= '$id'");

        if (!$ask) {
            die("Error in SQL query: " . mysqli_error($conn));
        }

        if (mysqli_num_rows($ask) == 1) {
            $uzivatel = mysqli_fetch_array($ask);
            $name = $uzivatel["name"];

            if ($uzivatel["mail"] == $mail) {
                $ask2 = mysqli_query($conn, "SELECT id, stand, `table` FROM place WHERE ticket_id= '$id'");

                if (!$ask2) {
                    die("Error in SQL query: " . mysqli_error($conn));
                }
                    echo'<p class="info">
                    Zde jsou Vaše lístky. Lístky si prosím uložte do mobilu nebo vytiskněte a vystřiněte na papír. Lístkem se prokážete u vstupu a s každým lístkem také můžete vyhrát nějakou cenu z tomboly ;-)
                </p>';

                while ($uzivatel2 = mysqli_fetch_array($ask2)) {
                    echo '
                  
                      <div class="ticket">
                          <div class="number">
                              <p><img src="log.png" alt="logo"></p>
                              <p><img src="https://api.qrserver.com/v1/create-qr-code/?size=88x88&ecc=L&format=svg&data=https://ples.junak-sumperk.cz/check/' . $uzivatel2['id'] . '/' . urlencode($mail) . '" alt="qr"></p>
                              <p><strong>#' . $uzivatel2['id'] . '</strong></p>
                          </div>
                  
                          <div class="content">
                              <div class="details">
                                  <div class="nz">15. šumperský <br><span class="nz2">SKAUTSKÝ PLES</span></div><br>
                                  <table class="fr">
                                      <tr>
                                          <td>' . $name. '</td>
                                      </tr>
                                      <tr>
                                          <td>(' . $mail . ')</td>
                                      </tr>
                                      <tr>
                                          <td>7. 2. 2026 v 19:00</td>
                                      </tr>
                                      <tr>
                                          <td>Sokolovna, Nový Malín</td>
                                      </tr>
                                      <tr>
                                          ';
                                          if($uzivatel2['stand'] == 0){
                                            echo'<td>Lístek na sezení u stolu </td></tr><tr><td><strong>' . $uzivatel2['table'] . '.</strong></td>';
                                          } else {
                                            echo'<td>Lístek na stání <strong>bez místa u stolu.</strong></td>';
                                          }
                                          echo '
                                      </tr>
                                  </table>
                              </div>
                          </div>
                      </div>';
                }
            }
            else{
                echo'<div class="container">
                <form method="GET">
                <h1>Pro přístup k lístkům zadejte mail</h1>
                <input type="mail" name="mail" placeholder="Zadejte e-mail" required>
                <input type="hidden" name="id" value="' . $id . '">
                <button>ověřit</button>
                <h1 style="color:red">Špatný email</h1>
                </form>
                </div>';
            }
        }
    }

    ?>

</body>

</html>
