import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api, ApiError, checkoutUrl, clearSession, loadSession } from "../src/lib/api";
const session = { user: { id:"customer", name:"Customer", email:"customer@example.test", role:"customer" }, csrf:"private-csrf-token", capabilities:{ stripe:false, manualPayments:false } };
const response = (data:unknown, status=200) => new Response(JSON.stringify(data), { status, headers:{"content-type":"application/json"} });
describe("customer API safety boundary", () => {
  beforeEach(() => { clearSession(); });
  afterEach(() => { vi.unstubAllGlobals(); clearSession(); });
  it("obtains a same-origin session and sends its CSRF token on a mutation", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response({data:session})).mockResolvedValueOnce(response({data:{id:"persisted-design"}})); vi.stubGlobal("fetch",fetch);
    await expect(api("/designs","POST",{name:"Evening suit"})).resolves.toEqual({id:"persisted-design"});
    expect(fetch).toHaveBeenNthCalledWith(1,"/api/v1/session",expect.objectContaining({credentials:"same-origin",cache:"no-store"}));
    expect(fetch).toHaveBeenNthCalledWith(2,"/api/v1/designs",expect.objectContaining({method:"POST",credentials:"same-origin",headers:expect.objectContaining({"X-CSRF-Token":"private-csrf-token"}),body:JSON.stringify({name:"Evening suit"})}));
  });
  it("does not report a successful save when the backend returns HTML", async () => {
    vi.stubGlobal("fetch",vi.fn().mockResolvedValueOnce(response({data:session})).mockResolvedValueOnce(new Response("<html>Service unavailable</html>",{status:503})));
    await expect(api("/designs","POST",{})).rejects.toMatchObject({name:"ApiError",status:503});
  });
  it("preserves a server rejection rather than accepting browser-side state as payment proof", async () => {
    vi.stubGlobal("fetch",vi.fn().mockResolvedValueOnce(response({data:session})).mockResolvedValueOnce(response({error:{code:"payment_unavailable",message:"Approved payments are unavailable."}},409)));
    await expect(api("/orders/order/checkout","POST",{kind:"balance"})).rejects.toMatchObject({status:409,code:"payment_unavailable"});
  });
  it("refreshes the session after auth rotation instead of retaining the old CSRF token", async () => {
    const fetch=vi.fn().mockResolvedValueOnce(response({data:session})).mockResolvedValueOnce(response({data:{...session,csrf:"rotated-token"}})).mockResolvedValueOnce(response({data:{ok:true}}));vi.stubGlobal("fetch",fetch);
    await loadSession();await loadSession(true);await api("/logout","POST");
    expect(fetch).toHaveBeenLastCalledWith("/api/v1/logout",expect.objectContaining({headers:expect.objectContaining({"X-CSRF-Token":"rotated-token"})}));
  });
  it("rejects redirect URLs outside genuine HTTPS Stripe Checkout", () => {
    expect(checkoutUrl("https://checkout.stripe.com/c/pay/session")).toBe("https://checkout.stripe.com/c/pay/session");
    for(const url of ["https://checkout.stripe.com.attacker.example/pay","http://checkout.stripe.com/pay","javascript:alert(1)","https://attacker.example/pay"]) expect(()=>checkoutUrl(url)).toThrow(ApiError);
  });
});
