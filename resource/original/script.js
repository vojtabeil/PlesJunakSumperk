
var block = false;
var over = false;
var sessiona;
var totalValue = 0;
var sesin = false;
var odpoctovac;
//var checkedCheckboxes = $('input[type="checkbox"]:checked').length || 0;
//      var numericValue = parseFloat(document.getElementById("numeric").value) || 0;

function updateTotalValue() {
    var checkedCheckboxes = $('input[type="checkbox"]:checked').length || 0;
    var numericElement = document.getElementById("numeric");
    var numericValue = 0;

    if (numericElement && numericElement.value) {
        numericValue = parseFloat(numericElement.value) || 0;
    }

    totalValue = checkedCheckboxes + numericValue;

    if (totalValue <= maxTicket) {
        block = false;
    } else {
        block = true;
        alert("Nelze rezervovat více lístků než je maximální počet!");
    }
}


/*DOČASNÁ REZERVACE*********************************************************************************************************************************/
function changeClass(id) {
    var checkbox = document.getElementById(id);
    var span = document.getElementById("span" + id);
    var emailR = $("#emailInput").val();
    var idCh = id;
    var act = false;
    var chec = document.getElementById(id).checked;

    if (over == true) {
        updateTotalValue();
        if (totalValue <= maxTicket && block == false) {
            if (chec == true) {
                act = true;
                span.className = "chair-selected";
                checkbox.checked = true;
                $.ajax({
                    type: "POST",
                    url: "ajax.php",
                    data: {
                        idCh: idCh,
                        emailR: emailR,
                        act: act
                    },
                    success: function (response) {

                        var ret = response;
                        if (ret == "save") {

                            span.className = "chair-selected";
                            checkbox.checked = true;

                            updateTotalValue();

                            if (totalValue == 1) {

                                spustOdpocti();
                            }
                        } else if (ret == "error") {
                            span.className = "chair";
                            checkbox.checked = false;
                        }
                    }
                });
            } else {
                span.className = "chair";
                checkbox.checked = false;
                updateTotalValue();
            }

            if (chec == false) {
                act = false;
                $.ajax({
                    type: "POST",
                    url: "ajax.php",
                    data: {
                        idCh: idCh,
                        emailR: emailR,
                        act: act
                    },
                    success: function (response) {
                        var ret = response;
                        if (ret == "save") {
                            span.className = "chair";
                            checkbox.checked = false;
                            updateTotalValue();
                            if (totalValue == 0) {
                                zastavitOdpoctovac();
                                var sesTime = "odstran";
                                $.ajax({
                                    type: "POST",
                                    url: "ajax.php",
                                    data: {
                                        sesTime: sesTime
                                    },
                                });
                            }
                        } else if (ret == "error") {
                            span.className = "chair-selected";
                            checkbox.checked = true;
                        }
                    }
                });
            }
        } else {
            span.className = "chair";
            checkbox.checked = false;
            updateTotalValue();
        }
    } else {
        alert("Zadejte mail!!");
    }
}

/*checking CHECKBOXŮ*********************************************************************************************/
function checking() {
    var checkboxes = document.querySelectorAll('input[name="places[]"]');
    var ids = Array.from(checkboxes).map(function (checkbox) {
        return checkbox.getAttribute("data-id");
    });

    $.ajax({
        type: "POST",
        url: "ajax.php",
        data: {
            ids: ids
        },
        success: function (response) {
            var data = response;

            checkboxes.forEach(function (checkbox) {
                var id = checkbox.getAttribute("data-id");
                var span = document.getElementById("span" + id);
                var checkboxData = data[id];

                if (checkboxData) {
                    var state = checkboxData.state;
                    var ticketId = checkboxData.ticket_id;
                    var timik = checkboxData.times;
                    var timikA = checkboxData.timesA;

                    if (state === "reserved") {
                        span.className = "chair-reserved";
                        checkbox.checked = false;
                        checkbox.disabled = true;
                    } else if (state === "book") {
                        if ((timikA - timik) <= 120) {
                            if (over && sessiona == ticketId) {
                                span.className = "chair-selected";
                                checkbox.checked = true;
                                checkbox.disabled = false;
                            } else {
                                span.className = "chair-reserved";
                                checkbox.checked = false;
                                checkbox.disabled = true;
                            }
                        } else {
                            span.className = "chair";
                            checkbox.checked = false;
                            checkbox.disabled = false;
                        }
                    } else if (state === "free") {
                        span.className = "chair";
                        checkbox.checked = false;
                        checkbox.disabled = false;
                    }
                }
            });
        }
    });
}

// Spustit checking ihned po načtení stránky pro všechny checkboxy s datovým atributem
window.addEventListener("load", function () {
    var checkboxes = document.querySelectorAll('input[data-id]');
    checkboxes.forEach(function (checkbox) {
        checking(checkbox);
    });

    var interval = setInterval(checking, 2000);
});



var timeoutId; // Globální proměnná pro uchování ID časovače

$(document).ready(function () {
    $("#emailInput").on("input", function () {
        clearTimeout(timeoutId); // Zrušení předchozího časovače (pokud existuje)
        timeoutId = setTimeout(function () {
            addRecord();
        }, 1000); // Po 2 sekundách spusť funkci addRecord()
    });
});
/*ODESÍLÁNÍ MAILU A TVORBA SESSION******************************************************************/
function addRecord() {
    var email = $("#emailInput").val();

    // Kontrola e-mailu pomocí regulárního výrazu
    var emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

    if (!emailPattern.test(email)) {
        // Pokud e-mail neodpovídá regulárnímu výrazu, můžete udělat něco, např. zobrazit chybu
        console.log("Chybný e-mailový formát");
        var zprava = "Chybný e-mailový formát";
        $("#overeni").text(zprava);
        return;
    }

    // Pokračujte s odesláním AJAX požadavku
    $.ajax({
        type: "POST",
        url: "ajax.php",
        data: {
            email: email
        },
        success: function (response) {
            try {
                var rec = JSON.parse(response);
                var state = rec.state;

                if (state == "exist" || state == "new") {
                    over = true;
                    sessiona = rec.id;
                    var zprava = "Mail byl ověřen";
                    $("#overeni").text(zprava);
                } else if (state == "error") {
                    var zprava = "Na tento mail rezervace již existuje";
                    $("#overeni").text(zprava);
                }
            } catch (e) {
                console.error("Chyba při zpracování JSON odpovědi: " + e);
            }
        },
        error: function (xhr, status, error) {
            console.error("Chyba při AJAX požadavku: " + status + ", " + error);
        }
    });
}

//number******************************************************************************************
jQuery('<div class="quantity-nav"><div class="quantity-button quantity-up">+</div><div class="quantity-button quantity-down">-</div></div>').insertAfter('.quantity input');
jQuery('.quantity').each(function () {
    var spinner = jQuery(this),
        input = spinner.find('input[type="number"]'),
        btnUp = spinner.find('.quantity-up'),
        btnDown = spinner.find('.quantity-down'),
        min = input.attr('min'),
        max = input.attr('max');

    btnUp.click(function () {
        var oldValue = parseFloat(input.val());
        if (over == true) {
            if (oldValue >= max) {
                var newVal = oldValue;
            } else {
                var newVal = oldValue + 1;
                spinner.find("input").val(newVal);
                spinner.find("input").trigger("change");
                updateTotalValue();
                if ((totalValue) <= maxTicket && block == false) {
                    numeri = true;
                    $.ajax({
                        type: "POST",
                        url: "ajax.php",
                        data: {
                            numeri: numeri
                        },
                    });
                    updateTotalValue()
                    if (totalValue == 1) {
                        spustOdpocti()
                    }
                } else {
                    spinner.find("input").val(oldValue);
                    spinner.find("input").trigger("change");
                }
                /*spinner.find("input").val(newVal);
                spinner.find("input").trigger("change");*/
            }

        } else {
            alert("Zadej mail!!");
        }

    });

    btnDown.click(function () {
        var oldValue = parseFloat(input.val());
        if (over == true) {
            if (oldValue <= min) {
                var newVal = oldValue;
            } else {
                var newVal = oldValue - 1;
                numeric = false;
                $.ajax({
                    type: "POST",
                    url: "ajax.php",
                    data: {
                        numeric: numeric
                    },
                });
            }
            spinner.find("input").val(newVal);
            spinner.find("input").trigger("change");
            updateTotalValue()
            if (totalValue == 0) {
                zastavitOdpoctovac();
                var sesTime = "odstran";
                $.ajax({
                    type: "POST",
                    url: "ajax.php",
                    data: {
                        sesTime: sesTime
                    },
                });
            }
        } else {
            alert("Zadej mail!!");
        }

    });


});
/********************************odpočítavání */
function spustOdpocti() {
    // Nastavení času odpočtu (2 minuty = 120 000 milisekund)
    var casOdpoctu = 120000;
    var pocatek = ziskatAktualniUnixCas();
    zastavitOdpoctovac();

    // Spuštění odpočtu
    odpoctovac = setInterval(function () {
        casOdpoctu -= 1000; // Snížení času odpočtu o 1 sekundu
        var aktual = ziskatAktualniUnixCas();
        var casik = aktual - pocatek;
        var konec = 120 - casik; // Oprava výpočtu zbývajícího času na 120 sekund
        //console.log("Zbývající čas: " + konec + " sekund");
        var zpravik = "Zbývající čas rezervace: " + konec + " sekund";
        $("#odpocet").text(zpravik);

        // Pokud odpočet skončil
        if (konec <= 0) {
            // Zastavení odpočítávání
            clearInterval(odpoctovac);

            // Odeslání zprávy pomocí AJAX
            // odesliZpravu();
            location.reload();
        }
    }, 1000); // Interval odpočtu (1 sekunda = 1000 milisekund)
}

function ziskatAktualniUnixCas() {
    return Math.floor(new Date().getTime() / 1000);
}

function zastavitOdpoctovac() {
    if (odpoctovac) {
        clearInterval(odpoctovac);
        odpoctovac = null; // Nastavte proměnnou na null, aby bylo jasné, že interval byl zastaven
    }
}

/*zooom obrázku******************************************/
/*document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('checkbox-container');
  const viewport = document.querySelector('.map-viewport');
  
  if (!container || !viewport) return;

  let scale = 1;
  let offsetX = 0;
  let offsetY = 0;
  let isDragging = false;
  let dragStartX, dragStartY;

  // Pro touch: pinching
  let touchStartDistance = 0;
  let lastScale = 1;

  const minScale = 1; // 🔒 Nelze jít pod 100%
  const maxScale = 3;

  // === Získání obrázku pro výpočet hranic ===
  const img = container.querySelector('img');

  // === Zoom kolečkem (myš) ===
  viewport.addEventListener('wheel', (e) => {
    e.preventDefault();
    const rect = viewport.getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;

    const worldX = (mouseX - offsetX) / scale;
    const worldY = (mouseY - offsetY) / scale;

    const zoomFactor = e.deltaY > 0 ? 0.9 : 1.1;
    const newScale = Math.min(Math.max(scale * zoomFactor, minScale), maxScale);

    const newOffsetX = mouseX - worldX * newScale;
    const newOffsetY = mouseY - worldY * newScale;

    scale = newScale;
    offsetX = newOffsetX;
    offsetY = newOffsetY;
    applyTransform();
  });

  // === Tažení myší ===
  viewport.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return;
    isDragging = true;
    dragStartX = e.clientX - offsetX;
    dragStartY = e.clientY - offsetY;
    viewport.style.cursor = 'grabbing';
    e.preventDefault();
  });

  window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;

    if (scale === 1) {
      offsetX = 0;
      offsetY = 0;
    } else {
      offsetX = e.clientX - dragStartX;
      offsetY = e.clientY - dragStartY;

      if (img && img.naturalWidth > 0) {
        const vpWidth = viewport.offsetWidth;
        const vpHeight = viewport.offsetHeight;
        const scaledImgWidth = img.naturalWidth * scale;
        const scaledImgHeight = img.naturalHeight * scale;

        const maxX = vpWidth - scaledImgWidth;
        const maxY = vpHeight - scaledImgHeight;

        offsetX = Math.max(maxX, Math.min(0, offsetX));
        offsetY = Math.max(maxY, Math.min(0, offsetY));
      }
    }

    applyTransform();
  });

  window.addEventListener('mouseup', () => {
    isDragging = false;
    viewport.style.cursor = 'grab';
  });

  // === Touch: začátek dotyku ===
  viewport.addEventListener('touchstart', (e) => {
    // Nezakazuj interakci s checkboxy a jejich značkami
    if (e.target.closest('.chair, .chair-reserved, .chair-selected, input[type="checkbox"]')) {
      return;
    }

    if (e.touches.length === 1) {
      isDragging = true;
      dragStartX = e.touches[0].clientX - offsetX;
      dragStartY = e.touches[0].clientY - offsetY;
      // preventDefault() NEVOLÁME – jen při pinch zoomu
    } else if (e.touches.length === 2) {
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      touchStartDistance = Math.sqrt(dx * dx + dy * dy);
      lastScale = scale;
      e.preventDefault(); // povolíme pinch zoom
    }
  });

  // === Touch: pohyb ===
  viewport.addEventListener('touchmove', (e) => {
    // Nezakazuj interakci s checkboxy
    if (e.target.closest('.chair, .chair-reserved, .chair-selected, input[type="checkbox"]')) {
      return;
    }

    if (e.touches.length === 1) {
      if (scale === 1) {
        offsetX = 0;
        offsetY = 0;
      } else {
        offsetX = e.touches[0].clientX - dragStartX;
        offsetY = e.touches[0].clientY - dragStartY;

        if (img && img.naturalWidth > 0) {
          const vpWidth = viewport.offsetWidth;
          const vpHeight = viewport.offsetHeight;
          const scaledImgWidth = img.naturalWidth * scale;
          const scaledImgHeight = img.naturalHeight * scale;

          const maxX = vpWidth - scaledImgWidth;
          const maxY = vpHeight - scaledImgHeight;

          offsetX = Math.max(maxX, Math.min(0, offsetX));
          offsetY = Math.max(maxY, Math.min(0, offsetY));
        }
      }
      applyTransform();
      e.preventDefault(); // povolíme drag
    } else if (e.touches.length === 2) {
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      const currentDistance = Math.sqrt(dx * dx + dy * dy);
      const zoomFactor = currentDistance / touchStartDistance;
      const newScale = Math.min(Math.max(lastScale * zoomFactor, minScale), maxScale);

      const centerX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      const centerY = (e.touches[0].clientY + e.touches[1].clientY) / 2;

      const rect = viewport.getBoundingClientRect();
      const mouseX = centerX - rect.left;
      const mouseY = centerY - rect.top;

      const worldX = (mouseX - offsetX) / scale;
      const worldY = (mouseY - offsetY) / scale;

      const newOffsetX = mouseX - worldX * newScale;
      const newOffsetY = mouseY - worldY * newScale;

      scale = newScale;
      offsetX = newOffsetX;
      offsetY = newOffsetY;

      applyTransform();
      e.preventDefault(); // povolíme pinch
    }
  });

  // === Touch: konec ===
  viewport.addEventListener('touchend', (e) => {
    if (e.touches.length < 2) {
      isDragging = false;
      touchStartDistance = 0;
    }
  });

  function applyTransform() {
    container.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    container.style.transformOrigin = '0 0';
  }

  // Inicializace
  applyTransform();
});*/