import { useState } from "react";

import NavBar from "../components/NavBar";

import "../styles/call.css";

export default function Call() {
  return (
    <div className="call-page">
    <NavBar />

      <div className="call-container">
        <div className="call-header">
          <div className="call-title">
            <img src="yeosang.jpg" className="call-icon"/>
            <span>Call name</span>
          </div>
        </div>

        <div className="call-layout">
          
        <div className="video-card main">
            <div className="video-overlay">
                <div className="card-content">
                    <div className="avatar">
                        <img src="yeosang.jpg" alt="User avatar" />
                    </div>
                    <span className="video-name">User name</span>
                </div>

                <div className="video-icons">
                    <button>
                        <img src="novideo_icon.png"/>
                    </button>
                    <button>
                        <img src="nomic_icon.png"/>
                    </button>
                </div>
            </div>
        </div>


            <div className="call-side">

                <div className="video-card">
                    <div className="card-content">
                        <div className="avatar">
                            <img src="yeosang.jpg"/>
                        </div>
                        <span className="video-name">User name</span>
                    </div>
                </div>


                <div className="video-card">
                    <div className="card-content">
                        <div className="avatar">
                            <img src="yeosang.jpg" alt="User avatar" />
                        </div>
                        <span className="video-name">User name</span>
                    </div>
                </div>

          </div>
        </div>

        <div className="call-controls">
            <button className="control-btn">
                <img src="novideo_icon.png" alt="Toggle video" />
            </button>
            <button className="control-btn">
                <img src="nomic_icon.png" alt="Toggle mic" />
            </button>
            <button className="end-call">
                <img src="phone_icon_white.png" alt="End call" />
            </button>
        </div>


      </div>
    </div>
  );
}
