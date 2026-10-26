import * as BunnySDK from "https://esm.sh/@bunny.net/edgescript-sdk@0.11.2";

BunnySDK.net.http.servePullZone().onOriginRequest((ctx) => ctx.request);
