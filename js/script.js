function createTransaction() {
  const name = 'Milena Camila Emanuelly';
  const documentNumber = '88170663385';
  const phone = '86929768706';
  const email = 'andreacarolinanovaes@gmail.com';

  const apiKey = 'sk_live_OxjuJmXzd6SEVlImMdB5pkNxka9gXGJUtHdhG4cWsb';
  const apiSecret = 'x';

  const authToken = btoa(`${apiKey}:${apiSecret}`);

  const url = 'https://api.dashboard.orbitapay.com.br/v1/transactions';

  const data = {
      "customer": {
          "name": name,
          "email": email,
          "phone": phone,
          "document": {
              "number": documentNumber,
              "type": "cpf"
          }
      },
      "paymentMethod": "pix",
      "amount": 3582,
      "postbackUrl": "https://pagamento.com",
      "traceable": true,
      "items": [
          {
              "unitPrice": 3582,
              "title": "Pagamento upsell",
              "quantity": 1,
              "tangible": true
          }
      ],
      "address": {
          "cep": "68907301",
          "complement": "Pantanal",
          "number": "304",
          "street": "Avenida Caubi Sérgio Melo",
          "district": "Pantanal",
          "city": "Macapá",
          "state": "AP"
      },
      "utmQuery": "string",
      "checkoutUrl": "string",
      "referrerUrl": "pagamento/upsell",
      "externalId": "string",
      "traceable": true
  };

  // Oculta o botão de pagamento
  document.getElementById('paymentButton').style.display = 'none';

  // Exibe a mensagem de carregamento
  document.getElementById('loadingMessage').style.display = 'block';

  fetch(url, {
      method: 'POST',
      headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Authorization': `Basic ${authToken}`
      },
      body: JSON.stringify(data),
  })
  .then(response => response.json())
  .then(responseData => {
      console.log(responseData.pix.qrcode);
      if (responseData.pix && responseData.pix.qrcode) {
          const pixCodeInput = document.getElementById('pixCode');
          pixCodeInput.value = responseData.pix.qrcode;

          // Oculta a mensagem de carregamento
          document.getElementById('loadingMessage').style.display = 'none';

          // Exibe o container do código PIX
          document.getElementById('pixCodeContainer').style.display = 'block';

          // Inicia a contagem regressiva
          startCountdown();
      } else {
          throw new Error('Erro ao gerar o código PIX.');
      }
  })
  .catch(error => {
      console.error('Erro:', error);
      alert('Erro ao gerar o código PIX.');
      // Oculta a mensagem de carregamento em caso de erro
      document.getElementById('loadingMessage').style.display = 'none';
      // Exibe novamente o botão de pagamento em caso de erro
      document.getElementById('paymentButton').style.display = 'block';
  });
}

function copyPixCode() {
  const pixCodeInput = document.getElementById('pixCode');
  const copyButton = document.querySelector('.copy-btn');
  pixCodeInput.select();
  pixCodeInput.setSelectionRange(0, 99999);
  document.execCommand('copy');
  copyButton.textContent = 'CÓDIGO COPIADO';
  setTimeout(() => {
      copyButton.textContent = 'COPIAR CÓDIGO';
  }, 2000);
}

function startCountdown() {
  let timeLeft = 8 * 60 + 19; 
  const timerDisplay = document.getElementById('timer');
  
  const countdownInterval = setInterval(() => {
      const minutes = Math.floor(timeLeft / 60);
      const seconds = timeLeft % 60;
      
      timerDisplay.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
      
      if (timeLeft <= 0) {
          clearInterval(countdownInterval);
          timerDisplay.textContent = '00:00';
      } else {
          timeLeft--;
      }
  }, 1000);
}

