import type { MetadataRoute } from "next";
import {servicePages} from "@/lib/service-pages";
export const dynamic="force-static";
export default function sitemap(): MetadataRoute.Sitemap {return ["","studio","enquiry","how-it-works","partners","designs",...Object.keys(servicePages)].map(path=>({url:"https://fashion.azenith.ca/"+(path?path+"/":"")}));}
