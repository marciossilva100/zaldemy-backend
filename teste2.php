<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Alien Falante</title>

<style>
body {
  height: 100vh;
  margin: 0;
  background: #0a0015;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-family: Arial, Helvetica, sans-serif;
  color: #e0f0ff;
}

/* AGORA VOCÊ PODE MUDAR AQUI SEM MEDO */
.container {
  position: relative;
  width: 360px; /* altere para qualquer valor */
  aspect-ratio: 1/1;
}

.alien {
  width: 100%;
  height: 100%;
  background: url('alien.png') center/contain no-repeat;
  position: relative;
}

/* ======================= */
/* BOCA BASE = SORRISO 🙂  */
/* ======================= */
.mouth {
  position: absolute;
  left: 50%;
  bottom: 43%;

  width: 17%;     /* antes 62px */
  height: 5%;     /* antes 12px */

  transform: translateX(-50%) scale(1);

  background: radial-gradient(circle at 50% 140%, #020617 5%, #000 70%);
  border-radius: 0 0 999px 999px;

  transition: transform .08s cubic-bezier(.34,1.56,.64,1),
              border-radius .15s ease;

  overflow: hidden;
}

/* céu */
.mouth::before{
  content:"";
  position:absolute;
  inset:0;
  background: linear-gradient(to bottom, #000, transparent);
  opacity:.65;
}

/* língua */
.mouth::after{
  content:"";
  position:absolute;
  bottom:-40%;
  left:50%;
  transform:translateX(-50%);
  width:55%;
  height:70%;
  background: radial-gradient(circle at 50% 30%, #fb7185, #be123c);
  border-radius:50%;
  transition: all .1s;
  opacity:.4;
}

/* ======================= */
/* ABERTURAS DE FALA 😮   */
/* ======================= */

.mouth.open-1 { transform: translateX(-50%) scaleY(1.3); }
.mouth.open-2 { transform: translateX(-50%) scale(1.1, 1.8); }
.mouth.open-3 { transform: translateX(-50%) scale(1.05, 1.5); }
.mouth.open-4 { transform: translateX(-50%) scale(1.02, 1.4); }

.mouth.open-2::after{
  opacity:.9;
}

textarea {
  margin-top: 40px;
  width: 90%;
  max-width: 520px;
  height: 110px;
  padding: 14px;
  border-radius: 14px;
  border: 2px solid #334155;
  background: #0f172a;
  color: white;
  font-size: 16px;
  resize: vertical;
}

button {
  margin-top: 16px;
  padding: 14px 36px;
  font-size: 17px;
  border: none;
  border-radius: 999px;
  background: #3b82f6;
  color: white;
  cursor: pointer;
  transition: all 0.2s;
}

button:hover {
  background: #60a5fa;
  transform: translateY(-2px);
}
</style>
</head>

<body>

<div class="container">
  <div class="alien">
    <div class="mouth" id="mouth"></div>
  </div>
</div>

<textarea id="texto">
Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.


</textarea>

<button onclick="falar()">Falar</button>

<script>
const mouth = document.getElementById("mouth");
const textArea = document.getElementById("texto");

const mouthStates = [
  "open-1",
  "open-2",
  "open-3",
  "open-4",
  "open-3",
  "open-2",
];

function falar() {
  const texto = textArea.value.trim();
  if (!texto) return;

  const duration = Math.max(1400, texto.length * 60);
  const startTime = Date.now();

  const interval = setInterval(() => {
    const elapsed = Date.now() - startTime;

    if (elapsed >= duration) {
      clearInterval(interval);
      mouth.className = "mouth";
      return;
    }

    const phase = Math.floor((elapsed / 90) % mouthStates.length);
    mouth.className = "mouth " + mouthStates[phase];

  }, 80);
}

textArea.addEventListener("keydown", e => {
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    falar();
  }
});
</script>

</body>
</html>
