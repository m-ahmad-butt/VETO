(() => {
  const KEY = "tpb-age-verified";
  const root = document.getElementById("tpb-age-gate");
  if (!root) return;

  const show = () => {
    root.hidden = false;
    document.body.classList.add("tpb-age-gate-locked");
  };

  const hide = () => {
    root.hidden = true;
    document.body.classList.remove("tpb-age-gate-locked");
  };

  try {
    if (!localStorage.getItem(KEY)) {
      show();
    }
  } catch (_) {
    show();
  }

  root.querySelectorAll("[data-tpb-age-confirm]").forEach((btn) => {
    btn.addEventListener("click", () => {
      try {
        localStorage.setItem(KEY, "true");
      } catch (_) {}
      hide();
    });
  });

  root.querySelectorAll("[data-tpb-age-deny]").forEach((btn) => {
    btn.addEventListener("click", () => {
      window.location.href = "https://www.google.com/";
    });
  });
})();
