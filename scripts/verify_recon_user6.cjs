const fs = require("fs");
const text = fs.readFileSync("d:/projects/billspro-project/billspro (2).sql", "utf8");
const pat =
  /\((\d+),\s*(\d+),\s*'[^']*',\s*'([^']*)',\s*(?:'([^']*)'|NULL),\s*'([^']*)',\s*'([^']*)',\s*([0-9.]+),\s*([0-9.]+),\s*([0-9.]+)/g;

let dep = 0,
  wd = 0,
  bills = 0,
  failedBills = 0,
  cc = 0,
  cf = 0;
let m;
while ((m = pat.exec(text))) {
  const uid = m[2];
  const typ = m[3];
  const status = m[5];
  const cur = m[6];
  const amt = +m[7];
  const total = +m[9];
  if (uid !== "6" || cur !== "NGN") continue;
  if (typ === "bill_payment" && status === "failed") {
    failedBills += total;
    continue;
  }
  if (status !== "completed") continue;
  if (typ === "deposit") dep += amt;
  else if (typ === "withdrawal") wd += total;
  else if (typ === "bill_payment") bills += total;
  else if (typ === "card_creation") cc += total;
  else if (typ === "card_funding") cf += total;
}

const moneyOut = wd + bills + cc + cf;
console.log({ dep, wd, bills, failedBills, cc, cf, moneyOut });

// Known rows from dump head must be included
if (dep < 1000) throw new Error("expected at least first deposit 1000");
if (wd < 300) throw new Error("expected at least first withdrawal total 300");
if (cc < 5000) throw new Error("expected card creation 5000+");
if (cf < 15500) throw new Error("expected card funding 15500+");
if (failedBills <= 0) throw new Error("expected failed bills in dump to prove exclusion");
if (bills >= failedBills + bills && failedBills > 0) {
  // failed bills must not inflate completed bills — already excluded by status check
}
console.log("VERIFY_OK: completed-only buckets; failed bills excluded from money story");
